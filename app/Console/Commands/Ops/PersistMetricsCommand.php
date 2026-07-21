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
    protected $signature = 'ops:metrics:persist
        {--demo= : 回填 N 天 demo 系统指标样本（仅非生产环境，1-90），用于本地观察趋势}';

    protected $description = 'Capture a system metric point sample and persist it for long-term trends';

    /**
     * 采集失败（如 /proc 不可读）时 warn + 退出 0，避免调度器写 ERROR 又被 watch-errors 采集成新告警。
     */
    public function handle(SystemMetricsCollector $collector, OpsMetricSampleService $service): int
    {
        $demo = $this->option('demo');

        if ($demo !== null && $demo !== '') {
            return $this->seedDemo($service, $demo);
        }

        try {
            $sample = $service->record($collector->collect());
            $this->info("Ops metric sample #{$sample->id} persisted.");
        } catch (Throwable $e) {
            $this->warn('Ops metric sample skipped: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function seedDemo(OpsMetricSampleService $service, mixed $demo): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('demo 数据回填在生产环境被禁用。');

            return self::FAILURE;
        }

        if (! is_numeric($demo)) {
            $this->error('demo 天数必须是数字。');

            return self::FAILURE;
        }

        $seeded = $service->seedDemo((int) $demo);
        $this->info("已回填 {$seeded} 条 demo 系统指标样本。");

        return self::SUCCESS;
    }
}
