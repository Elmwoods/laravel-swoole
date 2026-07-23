<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

class EscalateAlertsCommand extends Command
{
    protected $signature = 'ops:alerts:escalate {--dry-run : 只统计将升级的告警，不重推/不标记}';

    protected $description = 'Escalate open critical alerts left unacknowledged past the threshold (re-notify)';

    public function handle(AlertCenterService $service): int
    {
        try {
            $escalated = $service->escalateStaleAlerts((bool) $this->option('dry-run'));
            $suffix = $this->option('dry-run') ? '（dry-run，未重推）' : '';
            $this->info("升级未确认 critical 告警 {$escalated->count()} 条{$suffix}。");
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('告警升级跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
