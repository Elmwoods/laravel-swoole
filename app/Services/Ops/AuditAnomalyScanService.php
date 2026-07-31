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
    /**
     * 作用：注入告警中心服务（用于命中异常时升 security_audit 告警）。
     *
     * @param  AlertCenterService  $alerts  告警中心
     */
    public function __construct(private readonly AlertCenterService $alerts) {}

    /**
     * 作用：从上次游标之后扫描审计日志新行，命中敏感操作/失败登录暴增时升告警，并推进游标。
     *
     * @param  bool  $dryRun  干跑模式：只统计不升告警、不推进游标（供巡检探测用）
     * @return array 扫描结果统计（enabled/scanned/sensitive/bursts/last_id 等）
     *
     * 「为什么」：靠状态文件游标 last_id 保证只处理增量新行；首跑把游标初始化为当前 max、
     * 不对历史泛滥；单次最多处理 max_rows 行，避免积压时一次扫爆内存。
     */
    public function scan(bool $dryRun = false): array
    {
        // 配置开关：未开启则直接短路返回（boot-safe，默认开启）
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
        $maxRows = (int) config('ops.alerts.audit_anomaly.max_rows_per_run', 500); // 单次处理上限，防积压时扫爆

        // 只取游标之后、按 id 升序的增量新行
        $rows = AdminAuditLog::query()
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($maxRows)
            ->get();

        // 无新行：原样返回游标，不推进
        if ($rows->isEmpty()) {
            return ['enabled' => true, 'scanned' => 0, 'sensitive' => 0, 'bursts' => 0, 'last_id' => $lastId];
        }

        // 敏感操作白名单（module:action 形式）、暴增统计窗口与失败登录阈值，均可配置
        $sensitiveActions = array_map('strval', (array) config('ops.alerts.audit_anomaly.sensitive_actions', []));
        $window = (int) config('ops.alerts.audit_anomaly.window_minutes', 10);
        $threshold = (int) config('ops.alerts.audit_anomaly.failed_login_threshold', 5);

        $sensitiveCount = 0;
        $burstCount = 0;
        $burstSubjectsDone = []; // 同一主体（邮箱/IP）在本次扫描中只升一次暴增告警，去重

        foreach ($rows as $row) {
            // 命中敏感操作白名单（module:action）：逐行升“敏感后台操作”告警
            if (in_array("{$row->module}:{$row->action}", $sensitiveActions, true)) {
                $sensitiveCount++;

                if (! $dryRun) {
                    // 操作者标识：优先邮箱，缺失则回落 #用户ID
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

            // 判定是否为一次失败登录（admin.auth + login/login_locked + failure）
            $isFailedLogin = $row->module === 'admin.auth'
                && in_array($row->action, ['login', 'login_locked'], true)
                && $row->result === 'failure';

            if (! $isFailedLogin) {
                continue;
            }

            // 暴增主体：优先按邮箱聚合，无邮箱则按 IP；都没有则跳过
            $subject = (string) ($row->admin_email ?: $row->ip_address ?: '');

            // 空主体或本次已处理过该主体则跳过（同主体只升一次告警）
            if ($subject === '' || isset($burstSubjectsDone[$subject])) {
                continue;
            }

            $burstSubjectsDone[$subject] = true;

            // 统计该主体在窗口期内的失败登录总数（按邮箱或 IP 维度）
            $count = AdminAuditLog::query()
                ->where('module', 'admin.auth')
                ->whereIn('action', ['login', 'login_locked'])
                ->where('result', 'failure')
                ->where('created_at', '>=', now()->subMinutes($window))
                // 有邮箱按邮箱统计，否则按 IP 统计（when 的第三个参数是 false 分支）
                ->when(
                    (bool) $row->admin_email,
                    fn ($query) => $query->where('admin_email', $row->admin_email),
                    fn ($query) => $query->where('ip_address', $row->ip_address),
                )
                ->count();

            // 未达阈值不算暴增，跳过
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

        // 本批最后一行 id = 新游标（rows 按 id 升序，last 即最大）
        $newLastId = (int) $rows->last()->id;

        // 干跑不推进游标，保证探测不改变真实扫描进度
        if (! $dryRun) {
            $this->writeState(['last_id' => $newLastId]);
        }

        return ['enabled' => true, 'scanned' => $rows->count(), 'sensitive' => $sensitiveCount, 'bursts' => $burstCount, 'last_id' => $newLastId];
    }

    /**
     * 作用：重置扫描游标（删除状态文件），下次 scan 将重新按“首跑”初始化。
     *
     * @return void
     *
     * 「为什么」：删掉状态文件后 readState 返回空，scan 会把游标重设为当前 max，
     * 用于运维手动重置而非重放历史。
     */
    public function resetCursor(): void
    {
        $file = (string) config('ops.alerts.audit_anomaly.state_file');

        // 仅当配置了路径且文件存在才删除；@ 抑制删除失败告警
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 作用：读取游标状态文件并解析为数组。
     *
     * @return array 状态数组（含 last_id）；文件缺失/损坏/未配置时返回空数组
     *
     * 「为什么」：任何异常（读失败、非法 JSON）都回落空数组，让 scan 走首跑逻辑而非崩溃，boot-safe。
     */
    private function readState(): array
    {
        $file = (string) config('ops.alerts.audit_anomaly.state_file');

        try {
            // 未配置路径或文件不存在 → 空状态（触发首跑）
            if ($file === '' || ! is_file($file)) {
                return [];
            }

            $data = json_decode((string) file_get_contents($file), true);

            return is_array($data) ? $data : []; // 非数组（损坏）也回落空
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 作用：把游标状态写回状态文件（必要时递归创建目录）。
     *
     * @param  array  $state  待写入的状态（含 last_id）
     * @return void
     *
     * 「为什么」：写失败静默吞掉（见 catch），因为丢一次游标更新只会导致下次重扫少量行，
     * 不应阻断整体扫描；JSON_UNESCAPED_UNICODE 保留中文原文便于人工查看。
     */
    private function writeState(array $state): void
    {
        $file = (string) config('ops.alerts.audit_anomaly.state_file');

        if ($file === '') {
            return; // 未配置路径则不落盘
        }

        try {
            @mkdir(dirname($file), 0775, true); // 确保目录存在，已存在时 @ 抑制告警
            file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // 状态写失败不阻断扫描
        }
    }
}
