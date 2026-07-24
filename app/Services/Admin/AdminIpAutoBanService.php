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
    public function __construct(
        private readonly AdminIpAccessService $ipAccess,
        private readonly AlertCenterService $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(bool $dryRun = false): array
    {
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

        $window = max(1, (int) config('ops.security.auto_ban.window_minutes', 10));
        $threshold = max(1, (int) config('ops.security.auto_ban.threshold', 10));
        $banMinutes = max(1, (int) config('ops.security.auto_ban.ban_minutes', 60));
        $maxRows = max(1, (int) config('ops.security.auto_ban.max_rows_per_run', 500));
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
            $isFailedLogin = $row->module === 'admin.auth'
                && in_array($row->action, ['login', 'login_locked'], true)
                && $row->result === 'failure';

            if (! $isFailedLogin) {
                continue;
            }

            $ip = (string) ($row->ip_address ?? '');

            if ($ip === '' || isset($seen[$ip])) {
                continue;
            }

            $seen[$ip] = true;

            if ($this->isNeverBan($ip, $neverBan) || $this->ipAccess->matchesActiveAllow($ip)) {
                continue;
            }

            $count = AdminAuditLog::query()
                ->where('module', 'admin.auth')
                ->whereIn('action', ['login', 'login_locked'])
                ->where('result', 'failure')
                ->where('ip_address', $ip)
                ->where('created_at', '>=', now()->subMinutes($window))
                ->count();

            if ($count < $threshold) {
                continue;
            }

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

        $newLastId = $rows->isNotEmpty() ? (int) $rows->last()->id : $lastId;

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
     * @param  array<int, string>  $neverBan
     */
    private function isNeverBan(string $ip, array $neverBan): bool
    {
        foreach ($neverBan as $cidr) {
            $cidr = trim((string) $cidr);

            if ($cidr === '') {
                continue;
            }

            try {
                if (IpUtils::checkIp($ip, $cidr)) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
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

    private function writeState(array $state): void
    {
        $file = (string) config('ops.security.auto_ban.state_file');

        if ($file === '') {
            return;
        }

        try {
            @mkdir(dirname($file), 0775, true);
            file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // 状态写失败不阻断扫描
        }
    }
}
