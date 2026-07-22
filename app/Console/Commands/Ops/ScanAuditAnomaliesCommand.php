<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AuditAnomalyScanService;
use Illuminate\Console\Command;
use Throwable;

class ScanAuditAnomaliesCommand extends Command
{
    protected $signature = 'ops:audit:scan-anomalies
        {--dry-run : 只统计不发送/不推进游标}
        {--reset-cursor : 重置游标（下次从当前最大 id 重新初始化）}';

    protected $description = 'Scan admin audit logs for anomalies (failed-login bursts, sensitive actions) and raise alerts';

    public function handle(AuditAnomalyScanService $service): int
    {
        if ($this->option('reset-cursor')) {
            $service->resetCursor();
            $this->info('审计异常检测游标已重置。');
        }

        try {
            $result = $service->scan((bool) $this->option('dry-run'));

            if (($result['enabled'] ?? false) === false) {
                $this->info('审计异常检测未启用。');
            } elseif ($result['initialized'] ?? false) {
                $this->info("首次运行，游标初始化到 #{$result['last_id']}，本次不告警。");
            } else {
                $suffix = $this->option('dry-run') ? '（dry-run，未发送）' : '';
                $this->info("扫描 {$result['scanned']} 条：敏感操作 {$result['sensitive']}、失败登录暴增 {$result['bursts']}{$suffix}。");
            }
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('审计异常扫描跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
