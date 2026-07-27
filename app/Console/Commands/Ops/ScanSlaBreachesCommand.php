<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

class ScanSlaBreachesCommand extends Command
{
    protected $signature = 'ops:alerts:sla-scan {--dry-run : 只统计将违约的告警，不升 sla_breach}';

    protected $description = 'Scan unresolved alerts and raise sla_breach alerts for those past their ack/resolve SLA targets';

    public function handle(AlertCenterService $service): int
    {
        try {
            $breached = $service->scanSlaBreaches((bool) $this->option('dry-run'));
            $suffix = $this->option('dry-run') ? '（dry-run，未升警）' : '';
            $this->info("SLA 违约告警 {$breached->count()} 条{$suffix}。");
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('SLA 扫描跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
