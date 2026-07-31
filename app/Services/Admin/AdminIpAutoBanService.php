<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminSecuritySetting;
use App\Services\Ops\AlertCenterService;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * 滥用来源自动封禁：定时按 IP 统计失败登录，某 IP 在窗口内超阈值 → 写一条带过期的临时 deny 规则。
 *
 * 与 phase-22 AuditAnomalyScanService（按邮箱聚合、只升告警）不同，这里以 IP 为主聚合并生成
 * 封禁规则。用独立游标状态文件跟踪已扫描的 admin_audit_logs id，首跑只初始化游标不封历史。
 *
 * 防自锁护栏：默认 opt-in 关闭、never_ban 名单（默认含 loopback）跳过、命中 allow 白名单的 IP 跳过、
 * 封禁自动过期、人工 deny 规则不被覆盖。
 */
class AdminIpAutoBanService
{
    /**
     * @param  AdminIpAccessService  $ipAccess  IP 准入服务，用于写封禁规则、清过期、查白名单。
     * @param  AlertCenterService  $alerts  告警中心，封禁发生时升告警。
     */
    public function __construct(
        private readonly AdminIpAccessService $ipAccess,
        private readonly AlertCenterService $alerts,
    ) {}

    /**
     * 作用：扫描新增失败登录审计行，对窗口内超阈值的 IP 写临时封禁规则。
     *
     * 「为什么」用游标（last_id）增量扫描：只处理上次之后的新审计行，避免重复统计与全表扫描；
     * 首跑仅把游标对齐到当前最大 id，绝不对历史日志泛滥追封。
     *
     * @param  bool  $dryRun  为 true 时只统计将封禁的数量，不写任何规则 / 状态 / 告警。
     * @return array<string, mixed> 本次扫描结果（enabled/scanned/banned/released/last_id 等）。
     */
    public function scan(bool $dryRun = false): array
    {
        // opt-in 护栏：功能默认关闭，未开启直接短路返回。
        if (! (bool) AdminSecuritySetting::value('auto_ban_enabled')) {
            return ['enabled' => false, 'scanned' => 0, 'banned' => 0, 'released' => 0];
        }

        // 先清理到期封禁（dry-run 不改动）。
        $released = $dryRun ? 0 : $this->ipAccess->deleteExpiredAutoBans();

        $state = $this->readState();
        $lastId = (int) ($state['last_id'] ?? 0);

        // 首跑：初始化游标到当前最大 id，不对历史泛滥封禁。
        if (! array_key_exists('last_id', $state)) {
            $maxId = (int) (AdminAuditLog::query()->max('id') ?? 0);

            if (! $dryRun) {
                $this->writeState(['last_id' => $maxId]);
            }

            return ['enabled' => true, 'initialized' => true, 'scanned' => 0, 'banned' => 0, 'released' => $released, 'last_id' => $maxId];
        }

        // 统计窗口（分钟）：只数最近 $window 分钟内的失败登录。
        $window = max(1, (int) config('ops.security.auto_ban.window_minutes', 10));
        // 触发阈值：窗口内失败次数 >= 此值才封禁。
        $threshold = max(1, (int) config('ops.security.auto_ban.threshold', 10));
        // 封禁时长（分钟）：写进规则 expires_at。
        $banMinutes = max(1, (int) config('ops.security.auto_ban.ban_minutes', 60));
        // 单次扫描处理的审计行上限，防一跑吃掉过多数据。
        $maxRows = max(1, (int) config('ops.security.auto_ban.max_rows_per_run', 500));
        // 永不封禁名单（默认含 loopback），防自锁。
        $neverBan = (array) config('ops.security.auto_ban.never_ban', []);

        // 取一批新审计行（不限类型，游标据此推进），失败登录行在循环内筛。
        $rows = AdminAuditLog::query()
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($maxRows)
            ->get();

        $banned = 0;
        $seen = [];

        foreach ($rows as $row) {
            // 只关心「后台登录失败」类审计行（含被锁定 login_locked）。
            $isFailedLogin = $row->module === 'admin.auth'
                && in_array($row->action, ['login', 'login_locked'], true)
                && $row->result === 'failure';

            if (! $isFailedLogin) {
                continue;
            }

            $ip = (string) ($row->ip_address ?? '');

            // 空 IP 或本批已处理过的 IP 跳过，保证每个 IP 本轮只评估一次。
            if ($ip === '' || isset($seen[$ip])) {
                continue;
            }

            $seen[$ip] = true;

            // 防自锁双闸：命中 never_ban 名单或已在 allow 白名单的 IP 一律不封。
            if ($this->isNeverBan($ip, $neverBan) || $this->ipAccess->matchesActiveAllow($ip)) {
                continue;
            }

            // 对该 IP 在窗口内做完整计数（不受本批 maxRows 限制，统计真实频次）。
            $count = AdminAuditLog::query()
                ->where('module', 'admin.auth')
                ->whereIn('action', ['login', 'login_locked'])
                ->where('result', 'failure')
                ->where('ip_address', $ip)
                ->where('created_at', '>=', now()->subMinutes($window))
                ->count();

            // 未达阈值不封。
            if ($count < $threshold) {
                continue;
            }

            // dry-run：只累加计数，不落封禁。
            if ($dryRun) {
                $banned++;

                continue;
            }

            $rule = $this->ipAccess->autoBan($ip, $banMinutes);

            if ($rule === null) {
                // 已有人工规则等，跳过。
                continue;
            }

            $banned++;

            // 封禁成功即升告警，通知运维。

            $this->alerts->raiseAutoBanAlert(
                $ip,
                "{$ip} 近 {$window} 分钟失败登录 {$count} 次，已临时封禁至 ".$rule->expires_at?->toDateTimeString(),
                [
                    'ip' => $ip,
                    'failed_count' => $count,
                    'window_minutes' => $window,
                    'expires_at' => $rule->expires_at?->toDateTimeString(),
                ],
            );
        }

        // 游标推进到本批最后一行 id（据全部扫描行推进，非仅失败行），空批则保持不变。
        $newLastId = $rows->isNotEmpty() ? (int) $rows->last()->id : $lastId;

        // 非 dry-run 且游标确有前进时才持久化，避免回退或空写。
        if (! $dryRun && $newLastId > $lastId) {
            $this->writeState(['last_id' => $newLastId]);
        }

        return [
            'enabled' => true,
            'scanned' => $rows->count(),
            'banned' => $banned,
            'released' => $released,
            'last_id' => $newLastId,
        ];
    }

    /**
     * 作用：删除游标状态文件，使下次 scan 重新走「首跑初始化」逻辑。
     *
     * 「为什么」删文件而非清 last_id：缺失 last_id 键正是首跑判定条件，删文件最干净。
     */
    public function resetCursor(): void
    {
        $file = (string) config('ops.security.auto_ban.state_file');

        try {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        } catch (Throwable) {
            // 忽略
        }
    }

    /**
     * 作用：判断 IP 是否落在 never_ban 名单里（任一 CIDR 命中即豁免）。
     *
     * @param  string  $ip  待判定 IP。
     * @param  array<int, string>  $neverBan  永不封禁的 IP / CIDR 列表。
     * @return bool 命中任一条目返回 true。
     */
    private function isNeverBan(string $ip, array $neverBan): bool
    {
        foreach ($neverBan as $cidr) {
            $cidr = trim((string) $cidr);

            if ($cidr === '') {
                continue;
            }

            try {
                // 逐条 CIDR 匹配，命中即豁免。
                if (IpUtils::checkIp($ip, $cidr)) {
                    return true;
                }
            } catch (Throwable) {
                // 名单里的非法 CIDR 容错跳过，不影响其它条目。
                continue;
            }
        }

        return false;
    }

    /**
     * 作用：读取游标状态文件并解析为数组。
     *
     * 「为什么」各种异常都回退空数组：空数组无 last_id 键，会触发 scan 的首跑初始化，安全。
     *
     * @return array<string, mixed> 解析后的状态；文件缺失 / 损坏时为空数组。
     */
    private function readState(): array
    {
        $file = (string) config('ops.security.auto_ban.state_file');

        try {
            if ($file === '' || ! is_file($file)) {
                return [];
            }

            $data = json_decode((string) file_get_contents($file), true);

            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 作用：把游标状态写回状态文件（必要时创建目录）。
     *
     * @param  array  $state  待持久化的状态（含 last_id）。
     */
    private function writeState(array $state): void
    {
        $file = (string) config('ops.security.auto_ban.state_file');

        if ($file === '') {
            return;
        }

        try {
            // 目录不存在则递归创建，再写 JSON（保留中文不转义 unicode）。
            @mkdir(dirname($file), 0775, true);
            file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // 状态写失败不阻断扫描
        }
    }
}
