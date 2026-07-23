<?php

namespace App\Services\Ops;

use App\Models\AdminAuditLog;
use Throwable;

/**
 * 审计异常检测：定时扫描 admin_audit_logs 新行，命中失败登录暴增 / 敏感操作时升 security_audit 告警。
 *
 * 用状态文件里的游标 last_id 只处理新行（首跑初始化为当前 max，不对历史泛滥）。
 */
class AuditAnomalyScanService
{
    public function __construct(private readonly AlertCenterService $alerts) {}

    public function scan(bool $dryRun = false): array
    {
        if (! (bool) config('ops.alerts.audit_anomaly.enabled', true)) {
            return ['enabled' => false, 'scanned' => 0, 'sensitive' => 0, 'bursts' => 0];
        }

        $state = $this->readState();

        // 首跑：游标初始化为当前最大 id，只对之后的新行告警。
        if (! isset($state['last_id'])) {
            $maxId = (int) AdminAuditLog::query()->max('id');
            $this->writeState(['last_id' => $maxId]);

            return ['enabled' => true, 'initialized' => true, 'last_id' => $maxId, 'scanned' => 0, 'sensitive' => 0, 'bursts' => 0];
        }

        $lastId = (int) $state['last_id'];
        $maxRows = (int) config('ops.alerts.audit_anomaly.max_rows_per_run', 500);

        $rows = AdminAuditLog::query()
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($maxRows)
            ->get();

        if ($rows->isEmpty()) {
            return ['enabled' => true, 'scanned' => 0, 'sensitive' => 0, 'bursts' => 0, 'last_id' => $lastId];
        }

        $sensitiveActions = array_map('strval', (array) config('ops.alerts.audit_anomaly.sensitive_actions', []));
        $window = (int) config('ops.alerts.audit_anomaly.window_minutes', 10);
        $threshold = (int) config('ops.alerts.audit_anomaly.failed_login_threshold', 5);

        $sensitiveCount = 0;
        $burstCount = 0;
        $burstSubjectsDone = [];

        foreach ($rows as $row) {
            if (in_array("{$row->module}:{$row->action}", $sensitiveActions, true)) {
                $sensitiveCount++;

                if (! $dryRun) {
                    $actor = (string) ($row->admin_email ?: '#'.$row->admin_user_id);
                    $this->alerts->raiseAuditAnomalyAlert(
                        "audit:sensitive:{$row->id}",
                        'warning',
                        '敏感后台操作',
                        "{$actor} 执行 {$row->module}/{$row->action}（结果 {$row->result}，IP ".((string) ($row->ip_address ?: '未知')).'）。',
                        ['audit_log_id' => $row->id, 'module' => $row->module, 'action' => $row->action, 'result' => $row->result],
                    );
                }
            }

            $isFailedLogin = $row->module === 'admin.auth'
                && in_array($row->action, ['login', 'login_locked'], true)
                && $row->result === 'failure';

            if (! $isFailedLogin) {
                continue;
            }

            $subject = (string) ($row->admin_email ?: $row->ip_address ?: '');

            if ($subject === '' || isset($burstSubjectsDone[$subject])) {
                continue;
            }

            $burstSubjectsDone[$subject] = true;

            $count = AdminAuditLog::query()
                ->where('module', 'admin.auth')
                ->whereIn('action', ['login', 'login_locked'])
                ->where('result', 'failure')
                ->where('created_at', '>=', now()->subMinutes($window))
                ->when(
                    (bool) $row->admin_email,
                    fn ($query) => $query->where('admin_email', $row->admin_email),
                    fn ($query) => $query->where('ip_address', $row->ip_address),
                )
                ->count();

            if ($count < $threshold) {
                continue;
            }

            $burstCount++;

            if (! $dryRun) {
                $this->alerts->raiseAuditAnomalyAlert(
                    "audit:failed_login:{$subject}",
                    'critical',
                    '失败登录暴增',
                    "{$subject} 近 {$window} 分钟内失败登录 {$count} 次（IP ".((string) ($row->ip_address ?: '未知')).'）。',
                    ['subject' => $subject, 'window_minutes' => $window, 'failed_count' => $count, 'ip' => $row->ip_address],
                );
            }
        }

        $newLastId = (int) $rows->last()->id;

        if (! $dryRun) {
            $this->writeState(['last_id' => $newLastId]);
        }

        return ['enabled' => true, 'scanned' => $rows->count(), 'sensitive' => $sensitiveCount, 'bursts' => $burstCount, 'last_id' => $newLastId];
    }

    public function resetCursor(): void
    {
        $file = (string) config('ops.alerts.audit_anomaly.state_file');

        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    private function readState(): array
    {
        $file = (string) config('ops.alerts.audit_anomaly.state_file');

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
        $file = (string) config('ops.alerts.audit_anomaly.state_file');

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
