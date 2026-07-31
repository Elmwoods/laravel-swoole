<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsRedisMetricSampleService;
use App\Services\Ops\RedisMetricsService;
use App\Services\Ops\RedisMonitorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 每分钟采集一个 Redis 指标点并持久化，供多天趋势使用。
 *
 * 作用：由 RedisMetricsService 采集 Redis 指标快照，再由 RedisMonitorService 补上命中率，
 * 合并后交 OpsRedisMetricSampleService 落库为一条采样点，长期累积形成趋势曲线。
 *
 * $signature 选项：
 *   --demo=N  回填 N 天 demo Redis 指标样本（范围 1-90），仅非生产环境可用，
 *             用于本地/测试环境快速造数以观察趋势图表。
 *
 * 调度：分钟级定时任务（每分钟一个采样点）。
 *
 * 容错：采集失败（如 Redis 不可用）时 warn + 退出 0，避免调度器写 ERROR
 * 又被 watch-errors 采集成新告警。
 */
class PersistRedisMetricsCommand extends Command
{
    protected $signature = 'ops:redis-metrics:persist
        {--demo= : 回填 N 天 demo Redis 指标样本（仅非生产环境，1-90），用于本地观察趋势}';

    protected $description = 'Capture a Redis metric point sample and persist it for long-term trends';

    /**
     * 采集失败（如 Redis 不可用）时 warn + 退出 0，避免调度器写 ERROR 又被 watch-errors 采集成新告警。
     */
    public function handle(
        RedisMetricsService $metrics,
        RedisMonitorService $monitor,
        OpsRedisMetricSampleService $service,
    ): int {
        $demo = $this->option('demo');

        // 传了 --demo 走回填分支，不做实时采集
        if ($demo !== null && $demo !== '') {
            return $this->seedDemo($service, $demo);
        }

        try {
            // 先取 Redis 指标快照，再补上命中率一并落库为一条采样点
            $snapshot = $metrics->collect();
            $snapshot['hit_rate'] = $monitor->getHitRate();
            $sample = $service->record($snapshot);
            $this->info("Ops redis metric sample #{$sample->id} persisted.");
        } catch (Throwable $e) {
            // 容错：Redis 不可用等采集失败只 warn，不抛出、不非零退出
            $this->warn('Ops redis metric sample skipped: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function seedDemo(OpsRedisMetricSampleService $service, mixed $demo): int
    {
        // demo 回填仅限非生产环境，防止污染生产趋势数据
        if ($this->getLaravel()->environment('production')) {
            $this->error('demo 数据回填在生产环境被禁用。');

            return self::FAILURE;
        }

        // 天数参数必须是数字，否则视为无效输入
        if (! is_numeric($demo)) {
            $this->error('demo 天数必须是数字。');

            return self::FAILURE;
        }

        // 由服务层批量造 N 天样本，返回实际回填条数
        $seeded = $service->seedDemo((int) $demo);
        $this->info("已回填 {$seeded} 条 demo Redis 指标样本。");

        return self::SUCCESS;
    }
}
