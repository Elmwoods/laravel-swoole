<?php

namespace App\Services\Admin;

use App\Models\AdminIpRule;
use App\Models\AdminSecuritySetting;
use App\Models\AdminUser;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * 后台登录 IP 准入名单（CIDR 级白/黑名单）。
 *
 * 在认证入口（AdminAuthController::login）与 AdminAuthenticate 中间件强制：
 * - 黑名单模式（默认）：命中任一 active deny 规则 → 拒绝；否则放行（空名单=全部放行）。
 * - 白名单模式：命中任一 active allow 规则 → 放行；无任何 active allow 规则 → fail-open 放行
 *   （永不把所有人锁死）；有 allow 规则但不命中 → 拒绝。
 * - deny 规则始终优先于 allow。
 *
 * 每请求评估走 60s 缓存 + 写时失效，避免每个 authed 请求打 DB。
 */
class AdminIpAccessService
{
    /** 评估快照的缓存键：写规则 / 改设置时会 flush 此键。 */
    private const CACHE_KEY = 'admin_ip_access_snapshot';

    /** 快照缓存 TTL（秒）：60s 内复用，避免每个 authed 请求都打 DB。 */
    private const CACHE_TTL = 60;

    /**
     * 评估某来源 IP 是否被允许访问后台。
     *
     * 作用：按「未启用直接放行 → deny 优先 → 白名单模式（空则 fail-open）→ 黑名单兜底放行」
     * 的顺序给出准入判定与原因。
     *
     * @param  string  $ip  待评估的来源 IP。
     * @return array{allowed: bool, mode: string, matched: ?array, reason: string} 判定结果，含命中规则与原因码。
     */
    public function evaluate(string $ip): array
    {
        $snapshot = $this->snapshot();

        // 准入功能整体关闭：不做任何限制，直接放行。
        if (! $snapshot['enabled']) {
            return $this->result(true, $snapshot['mode'], null, 'disabled');
        }

        // deny 始终优先。
        $deny = $this->firstMatch($ip, $snapshot['rules'], 'deny');
        if ($deny !== null) {
            return $this->result(false, $snapshot['mode'], $deny, 'deny_matched');
        }

        if ($snapshot['mode'] === 'allowlist') {
            // 白名单模式：从快照里挑出全部 allow 规则。
            $allowRules = array_values(array_filter(
                $snapshot['rules'],
                static fn (array $rule): bool => $rule['type'] === 'allow',
            ));

            if ($allowRules === []) {
                // fail-open：白名单为空绝不锁死所有人。
                return $this->result(true, $snapshot['mode'], null, 'allowlist_empty_failopen');
            }

            $allow = $this->firstMatch($ip, $allowRules, 'allow');

            // 命中 allow → 放行；有名单但不命中 → 拒绝。
            return $allow !== null
                ? $this->result(true, $snapshot['mode'], $allow, 'in_allowlist')
                : $this->result(false, $snapshot['mode'], null, 'not_in_allowlist');
        }

        // blocklist 模式且未命中任何 deny。
        return $this->result(true, $snapshot['mode'], null, 'no_deny_match');
    }

    /**
     * 作用：evaluate() 的布尔快捷方式，只关心「是否放行」。
     *
     * @param  string  $ip  待评估 IP。
     * @return bool 允许访问返回 true。
     */
    public function allowedFor(string $ip): bool
    {
        return $this->evaluate($ip)['allowed'];
    }

    /**
     * 全部规则（按 type、id 稳定排序），供管理页展示。
     *
     * 作用：拉全量规则并序列化，用于后台管理页列表。
     *
     * @return array<int, array<string, mixed>> 序列化后的规则数组。
     */
    public function rules(): array
    {
        return AdminIpRule::query()
            ->orderBy('type')
            ->orderBy('id')
            ->get()
            ->map(fn (AdminIpRule $rule): array => $this->serializeRule($rule))
            ->all();
    }

    /**
     * 作用：新建 / 覆盖一条人工 IP 规则（allow 或 deny），并失效缓存。
     *
     * 「为什么」始终写成 manual + 永久（expires_at=null）：人工规则语义就是永久，
     * 即便覆盖了同网段的某条 auto 自动封禁行，也提升为不过期的人工规则。
     *
     * @param  string  $type  规则类型，仅允许 allow / deny（会先归一化大小写）。
     * @param  string  $cidr  单 IP 或 CIDR 网段。
     * @param  string|null  $label  备注标签，空白则存 null。
     * @param  AdminUser|null  $actor  创建者，记入 created_by。
     * @return AdminIpRule 新建或更新后的规则。
     *
     * @throws InvalidArgumentException 类型非法或 CIDR 无效时。
     */
    public function createRule(string $type, string $cidr, ?string $label, ?AdminUser $actor = null): AdminIpRule
    {
        $type = strtolower(trim($type));
        $cidr = trim($cidr);

        // 类型只接受 allow / deny，其它一律拒绝。
        if (! in_array($type, ['allow', 'deny'], true)) {
            throw new InvalidArgumentException('规则类型只能是 allow 或 deny。');
        }

        // 落库前校验 IP/CIDR 合法，避免脏数据污染评估。
        if (! $this->isValidCidr($cidr)) {
            throw new InvalidArgumentException('无效的 IP 或 CIDR 网段。');
        }

        // 唯一键 (type, cidr)：同网段同类型即覆盖，不产生重复规则。
        $rule = AdminIpRule::query()->updateOrCreate(
            ['type' => $type, 'cidr' => $cidr],
            [
                'label' => ($label !== null && trim($label) !== '') ? trim($label) : null,
                'is_active' => true,
                'created_by' => $actor?->id,
                // 人工规则始终是永久 manual（即便覆盖了某个 auto 封禁行）。
                'source' => 'manual',
                'expires_at' => null,
            ],
        );

        // 规则变更后必须失效快照缓存，否则 60s 内的评估读到旧规则。
        $this->flushCache();

        return $rule;
    }

    /**
     * 作用：启用 / 停用一条规则，并在实际改动时失效缓存。
     *
     * @param  int  $id  规则主键。
     * @param  bool  $active  目标启用状态。
     * @return bool 有行被更新时返回 true。
     */
    public function toggleRule(int $id, bool $active): bool
    {
        $updated = AdminIpRule::query()
            ->whereKey($id)
            ->update(['is_active' => $active]) > 0;

        // 仅在确有更新时才 flush，省掉无谓的缓存清理。
        if ($updated) {
            $this->flushCache();
        }

        return $updated;
    }

    /**
     * 作用：删除一条规则，并在实际删除时失效缓存。
     *
     * @param  int  $id  规则主键。
     * @return bool 有行被删除时返回 true。
     */
    public function deleteRule(int $id): bool
    {
        $deleted = AdminIpRule::query()->whereKey($id)->delete() > 0;

        if ($deleted) {
            $this->flushCache();
        }

        return $deleted;
    }

    /**
     * 作用：读取全部安全设置键值（供管理页回显）。
     *
     * @return array<string, mixed> 安全设置键值表。
     */
    public function settings(): array
    {
        return AdminSecuritySetting::allValues();
    }

    /**
     * 只认白名单里的两个键；写后失效缓存。
     *
     * 作用：按白名单挑键更新安全设置（准入开关 / 模式 / 自动封禁开关），写后失效缓存并回显。
     *
     * 「为什么」逐键 array_key_exists 判断：只更新入参里显式带的键，未带的键保持原值，
     * 支持前端做局部字段更新而不误清其它设置。
     *
     * @param  array  $payload  入参，仅识别 ip_access_enabled / ip_access_mode / auto_ban_enabled。
     * @return array<string, mixed> 更新后的完整设置。
     */
    public function updateSettings(array $payload): array
    {
        if (array_key_exists('ip_access_enabled', $payload)) {
            AdminSecuritySetting::setValue('ip_access_enabled', (bool) $payload['ip_access_enabled']);
        }

        if (array_key_exists('ip_access_mode', $payload)) {
            $mode = (string) $payload['ip_access_mode'];
            AdminSecuritySetting::setValue(
                'ip_access_mode',
                // 非法模式一律落到安全默认 blocklist（黑名单更宽松，不会锁死所有人）。
                in_array($mode, AdminSecuritySetting::MODES, true) ? $mode : 'blocklist',
            );
        }

        if (array_key_exists('auto_ban_enabled', $payload)) {
            AdminSecuritySetting::setValue('auto_ban_enabled', (bool) $payload['auto_ban_enabled']);
        }

        // 设置变更同样影响评估快照，必须失效缓存。
        $this->flushCache();

        return $this->settings();
    }

    /**
     * 自动封禁：写/续期一条带过期的临时 deny 规则。
     *
     * 不覆盖人工规则：同 (deny, cidr) 若已是 manual → 跳过返回 null（人工规则优先保留）；
     * 若已是 auto → 续期 expires_at；不存在 → 新建 source=auto。
     *
     * 作用：为某 IP 写入 / 续期一条带过期时间的临时 deny 规则。
     *
     * @param  string  $ip  待封禁 IP（会当作 CIDR 校验）。
     * @param  int  $minutes  封禁时长（分钟），至少 1。
     * @param  string|null  $label  备注，默认「自动封禁：失败登录暴增」。
     * @return AdminIpRule|null 新建 / 续期的规则；命中人工规则或 IP 非法时返回 null。
     */
    public function autoBan(string $ip, int $minutes, ?string $label = null): ?AdminIpRule
    {
        $cidr = trim($ip);
        // 时长下限兜底 1 分钟，避免 0 / 负数造出「已过期即失效」的无效封禁。
        $minutes = max(1, $minutes);

        // IP 非法则不封，防脏数据。
        if (! $this->isValidCidr($cidr)) {
            return null;
        }

        // 先查同网段是否已有 deny 规则，用于判断是否碰到人工规则。
        $existing = AdminIpRule::query()
            ->where('type', 'deny')
            ->where('cidr', $cidr)
            ->first();

        if ($existing !== null && $existing->source === 'manual') {
            // 已有人工 deny：保留人工语义，不改成会过期的 auto。
            return null;
        }

        $rule = AdminIpRule::query()->updateOrCreate(
            ['type' => 'deny', 'cidr' => $cidr],
            [
                'label' => $label ?? '自动封禁：失败登录暴增',
                'is_active' => true,
                'created_by' => null,
                // 标记为 auto + 设过期时间，使其可被 deleteExpiredAutoBans 自动回收。
                'source' => 'auto',
                'expires_at' => now()->addMinutes($minutes),
            ],
        );

        $this->flushCache();

        return $rule;
    }

    /**
     * 清理已过期的自动封禁规则（不动人工规则 / 未过期规则）。
     *
     * 作用：删除全部已到期的 auto deny 规则，让被临时封禁的 IP 自动解封。
     *
     * @return int 实际删除的规则数。
     */
    public function deleteExpiredAutoBans(): int
    {
        $deleted = AdminIpRule::query()
            // 三重限定：仅 auto 来源、有过期时间、且已到期 —— 人工 / 未过期规则不受影响。
            ->where('source', 'auto')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        if ($deleted > 0) {
            $this->flushCache();
        }

        return $deleted;
    }

    /**
     * 该 IP 是否命中任一「启用且未过期」的 allow 规则（自动封禁跳过白名单来源）。
     *
     * 作用：供自动封禁模块判断——白名单来源不应被封。
     *
     * @param  string  $ip  待判定 IP。
     * @return bool 命中任一 active allow 规则返回 true。
     */
    public function matchesActiveAllow(string $ip): bool
    {
        // 从快照里过滤出 allow 规则再逐条匹配。
        $allowRules = array_values(array_filter(
            $this->snapshot()['rules'],
            static fn (array $rule): bool => $rule['type'] === 'allow',
        ));

        return $this->firstMatch($ip, $allowRules, 'allow') !== null;
    }

    /**
     * 校验字符串是不是合法的单 IP 或 CIDR 网段（v4/v6）。
     *
     * 作用：统一校验人工 / 自动写入的地址，防非法 CIDR 污染规则表。
     *
     * @param  string  $value  待校验的 IP 或 CIDR 字符串。
     * @return bool 合法返回 true。
     */
    public function isValidCidr(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        // 不含「/」：当作单个 IP 校验（v4 或 v6 皆可）。
        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        // 含「/」：拆成 IP 与前缀两段。
        [$ip, $prefix] = explode('/', $value, 2);

        // 前缀必须是纯数字，否则非法。
        if ($prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }

        $prefix = (int) $prefix;

        // IPv4：前缀范围 0-32。
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix >= 0 && $prefix <= 32;
        }

        // IPv6：前缀范围 0-128。
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefix >= 0 && $prefix <= 128;
        }

        return false;
    }

    /**
     * 作用：失效评估快照缓存，使下次评估读到最新规则 / 设置。
     *
     * 「为什么」吞掉异常：缓存驱动不可用不应阻断业务，下次评估会直接回源 DB。
     */
    public function flushCache(): void
    {
        try {
            cache()->forget(self::CACHE_KEY);
        } catch (Throwable) {
            // 缓存不可用时忽略：下次评估直接读 DB。
        }
    }

    /**
     * 评估所需的最小快照（enabled + mode + active 规则），60s 缓存。
     *
     * 作用：优先走缓存返回评估快照；缓存不可用时回源直建。
     *
     * @return array{enabled: bool, mode: string, rules: array<int, array{type: string, cidr: string}>} 评估快照。
     */
    private function snapshot(): array
    {
        try {
            // 60s 缓存：高频 authed 请求复用同一快照，减轻 DB 压力。
            return cache()->remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->buildSnapshot());
        } catch (Throwable) {
            // 缓存异常时降级：直接重建快照，保证评估不中断。
            return $this->buildSnapshot();
        }
    }

    /**
     * 作用：从 DB 直接构建评估快照（读设置 + 拉 active 且未过期的规则）。
     *
     * @return array{enabled: bool, mode: string, rules: array<int, array{type: string, cidr: string}>} 评估快照。
     */
    private function buildSnapshot(): array
    {
        $settings = AdminSecuritySetting::allValues();

        $rules = [];
        try {
            $rules = AdminIpRule::query()
                ->where('is_active', true)
                // 只取「无过期时间」或「过期时间晚于现在」的规则，天然排除到期未清的 auto 封禁。
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get(['type', 'cidr'])
                ->map(static fn (AdminIpRule $rule): array => [
                    'type' => (string) $rule->type,
                    'cidr' => (string) $rule->cidr,
                ])
                ->all();
        } catch (Throwable) {
            // 规则查询异常时降级为空规则集（黑名单模式下等于全放行，白名单下走 fail-open）。
            $rules = [];
        }

        $mode = (string) ($settings['ip_access_mode'] ?? 'blocklist');

        return [
            'enabled' => (bool) ($settings['ip_access_enabled'] ?? false),
            // 非法 / 缺失 mode 一律回落 blocklist（更安全，不会锁死所有人）。
            'mode' => in_array($mode, AdminSecuritySetting::MODES, true) ? $mode : 'blocklist',
            'rules' => $rules,
        ];
    }

    /**
     * 作用：在给定规则集中找到第一条指定类型且匹配该 IP 的规则。
     *
     * @param  string  $ip  待匹配 IP。
     * @param  array<int, array{type: string, cidr: string}>  $rules  规则集。
     * @param  string  $type  只考虑此类型（allow / deny）。
     * @return array{type: string, cidr: string}|null 命中的规则；无命中返回 null。
     */
    private function firstMatch(string $ip, array $rules, string $type): ?array
    {
        foreach ($rules as $rule) {
            // 跳过类型不符的规则。
            if ($rule['type'] !== $type) {
                continue;
            }

            // 命中 CIDR 匹配即返回（短路，取第一条）。
            if ($this->matches($ip, $rule['cidr'])) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * 作用：判断单个 IP 是否落在某 CIDR / IP 网段内。
     *
     * 「为什么」委托 Symfony IpUtils：统一处理 v4/v6 与 CIDR 前缀匹配，避免手写位运算出错。
     *
     * @param  string  $ip  待判定 IP。
     * @param  string  $cidr  单 IP 或 CIDR 网段。
     * @return bool 落在网段内返回 true；非法 CIDR 容错返回 false。
     */
    private function matches(string $ip, string $cidr): bool
    {
        try {
            return IpUtils::checkIp($ip, $cidr);
        } catch (Throwable) {
            // 存储的非法 CIDR：容错跳过，不影响其它规则评估。
            return false;
        }
    }

    /**
     * 作用：把评估结果拼装成统一结构，便于调用方读取与记录原因码。
     *
     * @param  bool  $allowed  是否允许。
     * @param  string  $mode  当前模式（allowlist / blocklist）。
     * @param  array|null  $matched  命中的规则，可空。
     * @param  string  $reason  原因码（如 deny_matched / in_allowlist）。
     * @return array{allowed: bool, mode: string, matched: ?array, reason: string} 结构化结果。
     */
    private function result(bool $allowed, string $mode, ?array $matched, string $reason): array
    {
        return [
            'allowed' => $allowed,
            'mode' => $mode,
            'matched' => $matched,
            'reason' => $reason,
        ];
    }

    /**
     * 作用：把 AdminIpRule 模型序列化成管理页所需的扁平数组。
     *
     * @param  AdminIpRule  $rule  规则模型。
     * @return array<string, mixed> 序列化后的规则字段。
     */
    private function serializeRule(AdminIpRule $rule): array
    {
        return [
            'id' => $rule->id,
            'type' => $rule->type,
            'cidr' => $rule->cidr,
            'label' => $rule->label,
            'is_active' => (bool) $rule->is_active,
            'source' => $rule->source ?? 'manual',
            'expires_at' => optional($rule->expires_at)->toDateTimeString(),
            'created_at' => optional($rule->created_at)->toDateTimeString(),
        ];
    }
}
