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
    private const CACHE_KEY = 'admin_ip_access_snapshot';

    private const CACHE_TTL = 60;

    /**
     * 评估某来源 IP 是否被允许访问后台。
     *
     * @return array{allowed: bool, mode: string, matched: ?array, reason: string}
     */
    public function evaluate(string $ip): array
    {
        $snapshot = $this->snapshot();

        if (! $snapshot['enabled']) {
            return $this->result(true, $snapshot['mode'], null, 'disabled');
        }

        // deny 始终优先。
        $deny = $this->firstMatch($ip, $snapshot['rules'], 'deny');
        if ($deny !== null) {
            return $this->result(false, $snapshot['mode'], $deny, 'deny_matched');
        }

        if ($snapshot['mode'] === 'allowlist') {
            $allowRules = array_values(array_filter(
                $snapshot['rules'],
                static fn (array $rule): bool => $rule['type'] === 'allow',
            ));

            if ($allowRules === []) {
                // fail-open：白名单为空绝不锁死所有人。
                return $this->result(true, $snapshot['mode'], null, 'allowlist_empty_failopen');
            }

            $allow = $this->firstMatch($ip, $allowRules, 'allow');

            return $allow !== null
                ? $this->result(true, $snapshot['mode'], $allow, 'in_allowlist')
                : $this->result(false, $snapshot['mode'], null, 'not_in_allowlist');
        }

        // blocklist 模式且未命中任何 deny。
        return $this->result(true, $snapshot['mode'], null, 'no_deny_match');
    }

    public function allowedFor(string $ip): bool
    {
        return $this->evaluate($ip)['allowed'];
    }

    /**
     * 全部规则（按 type、id 稳定排序），供管理页展示。
     *
     * @return array<int, array<string, mixed>>
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

    public function createRule(string $type, string $cidr, ?string $label, ?AdminUser $actor = null): AdminIpRule
    {
        $type = strtolower(trim($type));
        $cidr = trim($cidr);

        if (! in_array($type, ['allow', 'deny'], true)) {
            throw new InvalidArgumentException('规则类型只能是 allow 或 deny。');
        }

        if (! $this->isValidCidr($cidr)) {
            throw new InvalidArgumentException('无效的 IP 或 CIDR 网段。');
        }

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

        $this->flushCache();

        return $rule;
    }

    public function toggleRule(int $id, bool $active): bool
    {
        $updated = AdminIpRule::query()
            ->whereKey($id)
            ->update(['is_active' => $active]) > 0;

        if ($updated) {
            $this->flushCache();
        }

        return $updated;
    }

    public function deleteRule(int $id): bool
    {
        $deleted = AdminIpRule::query()->whereKey($id)->delete() > 0;

        if ($deleted) {
            $this->flushCache();
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return AdminSecuritySetting::allValues();
    }

    /**
     * 只认白名单里的两个键；写后失效缓存。
     *
     * @return array<string, mixed>
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
                in_array($mode, AdminSecuritySetting::MODES, true) ? $mode : 'blocklist',
            );
        }

        if (array_key_exists('auto_ban_enabled', $payload)) {
            AdminSecuritySetting::setValue('auto_ban_enabled', (bool) $payload['auto_ban_enabled']);
        }

        $this->flushCache();

        return $this->settings();
    }

    /**
     * 自动封禁：写/续期一条带过期的临时 deny 规则。
     *
     * 不覆盖人工规则：同 (deny, cidr) 若已是 manual → 跳过返回 null（人工规则优先保留）；
     * 若已是 auto → 续期 expires_at；不存在 → 新建 source=auto。
     */
    public function autoBan(string $ip, int $minutes, ?string $label = null): ?AdminIpRule
    {
        $cidr = trim($ip);
        $minutes = max(1, $minutes);

        if (! $this->isValidCidr($cidr)) {
            return null;
        }

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
                'source' => 'auto',
                'expires_at' => now()->addMinutes($minutes),
            ],
        );

        $this->flushCache();

        return $rule;
    }

    /**
     * 清理已过期的自动封禁规则（不动人工规则 / 未过期规则）。
     */
    public function deleteExpiredAutoBans(): int
    {
        $deleted = AdminIpRule::query()
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
     */
    public function matchesActiveAllow(string $ip): bool
    {
        $allowRules = array_values(array_filter(
            $this->snapshot()['rules'],
            static fn (array $rule): bool => $rule['type'] === 'allow',
        ));

        return $this->firstMatch($ip, $allowRules, 'allow') !== null;
    }

    /**
     * 校验字符串是不是合法的单 IP 或 CIDR 网段（v4/v6）。
     */
    public function isValidCidr(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$ip, $prefix] = explode('/', $value, 2);

        if ($prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }

        $prefix = (int) $prefix;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix >= 0 && $prefix <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefix >= 0 && $prefix <= 128;
        }

        return false;
    }

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
     * @return array{enabled: bool, mode: string, rules: array<int, array{type: string, cidr: string}>}
     */
    private function snapshot(): array
    {
        try {
            return cache()->remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->buildSnapshot());
        } catch (Throwable) {
            return $this->buildSnapshot();
        }
    }

    /**
     * @return array{enabled: bool, mode: string, rules: array<int, array{type: string, cidr: string}>}
     */
    private function buildSnapshot(): array
    {
        $settings = AdminSecuritySetting::allValues();

        $rules = [];
        try {
            $rules = AdminIpRule::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get(['type', 'cidr'])
                ->map(static fn (AdminIpRule $rule): array => [
                    'type' => (string) $rule->type,
                    'cidr' => (string) $rule->cidr,
                ])
                ->all();
        } catch (Throwable) {
            $rules = [];
        }

        $mode = (string) ($settings['ip_access_mode'] ?? 'blocklist');

        return [
            'enabled' => (bool) ($settings['ip_access_enabled'] ?? false),
            'mode' => in_array($mode, AdminSecuritySetting::MODES, true) ? $mode : 'blocklist',
            'rules' => $rules,
        ];
    }

    /**
     * @param  array<int, array{type: string, cidr: string}>  $rules
     * @return array{type: string, cidr: string}|null
     */
    private function firstMatch(string $ip, array $rules, string $type): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['type'] !== $type) {
                continue;
            }

            if ($this->matches($ip, $rule['cidr'])) {
                return $rule;
            }
        }

        return null;
    }

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
     * @return array{allowed: bool, mode: string, matched: ?array, reason: string}
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
     * @return array<string, mixed>
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
