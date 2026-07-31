<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsMetricSamplePruneService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * 清理过期的系统指标采样（ops_metric_samples）。
 *
 * 作用：删除超过保留天数的系统指标采样点，配合 ops:metrics:persist 的分钟级写入控制表体量。
 *
 * $signature 选项：
 *   --days=N   保留天数，默认 30，合法范围 7-3650；由服务层 normalizeRetentionDays 校验并归一化。
 *   --dry-run  只统计将删除的条数，不实际删除，用于预演确认影响面。
 *
 * 调度：低频定时任务（通常每日一次）。
 * 退出码：参数非法（天数非数字/越界）时 error + 返回 FAILURE；正常清理返回 SUCCESS。
 */
class PruneMetricSamplesCommand extends Command
{
    protected $signature = 'ops:metrics:prune
        {--days= : 系统指标采样保留天数，默认 30，范围 7-3650}
        {--dry-run : 只统计将删除的采样数量，不实际删除}';

    protected $description = 'Prune old system metric samples';

    public function handle(OpsMetricSamplePruneService $pruner): int
    {
        try {
            // 归一化保留天数：null 走默认值，越界/非法则抛 InvalidArgumentException
            $days = $pruner->normalizeRetentionDays($this->daysOption());
        } catch (InvalidArgumentException $e) {
            // 参数非法时以 FAILURE 退出，让调用方明确知道未执行清理
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // dry-run：只统计，不删除
        if ($this->option('dry-run')) {
            $count = $pruner->countPrunable($days);
            $this->info("将删除 {$count} 条 {$days} 天以前的系统指标采样。");

            return self::SUCCESS;
        }

        // 实际删除并回报删除条数
        $deleted = $pruner->prune($days);
        $this->info("已删除 {$deleted} 条 {$days} 天以前的系统指标采样。");

        return self::SUCCESS;
    }

    private function daysOption(): ?int
    {
        $value = $this->option('days');

        // 未传 --days 时返回 null，交由服务层套用默认保留天数
        if ($value === null || $value === '') {
            return null;
        }

        // 传了但非数字视为非法参数
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('系统指标采样保留天数必须是数字。');
        }

        return (int) $value;
    }
}
