<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsMetricSampleService;
use App\Services\Ops\SystemMetricsCollector;
use Illuminate\Console\Command;
use Throwable;

/**
 * 每分钟采集一个系统指标点并持久化，供多天趋势使用。
 */
class PersistMetricsCommand extends Command
{
    protected $signature = 'ops:metrics:persist';

    protected $description = 'Capture a system metric point sample and persist it for long-term trends';

    /**
     * 采集失败（如 /proc 不可读）时 warn + 退出 0，避免调度器写 ERROR 又被 watch-errors 采集成新告警。
     */
    public function handle(SystemMetricsCollector $collector, OpsMetricSampleService $service): int
    {
        try {
            $sample = $service->record($collector->collect());
            $this->info("Ops metric sample #{$sample->id} persisted.");
        } catch (Throwable $e) {
            $this->warn('Ops metric sample skipped: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
