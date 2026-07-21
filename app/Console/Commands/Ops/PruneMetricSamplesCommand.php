<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsMetricSamplePruneService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneMetricSamplesCommand extends Command
{
    protected $signature = 'ops:metrics:prune
        {--days= : 系统指标采样保留天数，默认 30，范围 7-3650}
        {--dry-run : 只统计将删除的采样数量，不实际删除}';

    protected $description = 'Prune old system metric samples';

    public function handle(OpsMetricSamplePruneService $pruner): int
    {
        try {
            $days = $pruner->normalizeRetentionDays($this->daysOption());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = $pruner->countPrunable($days);
            $this->info("将删除 {$count} 条 {$days} 天以前的系统指标采样。");

            return self::SUCCESS;
        }

        $deleted = $pruner->prune($days);
        $this->info("已删除 {$deleted} 条 {$days} 天以前的系统指标采样。");

        return self::SUCCESS;
    }

    private function daysOption(): ?int
    {
        $value = $this->option('days');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('系统指标采样保留天数必须是数字。');
        }

        return (int) $value;
    }
}
