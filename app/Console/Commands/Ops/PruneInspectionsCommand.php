<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsInspectionPruneService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneInspectionsCommand extends Command
{
    protected $signature = 'ops:inspections:prune
        {--days= : 巡检历史保留天数，默认 14，范围 3-3650}
        {--dry-run : 只统计将删除的巡检记录数量，不实际删除}';

    protected $description = 'Prune old ops inspection history records';

    public function handle(OpsInspectionPruneService $pruner): int
    {
        try {
            $days = $pruner->normalizeRetentionDays($this->daysOption());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = $pruner->countPrunable($days);
            $this->info("将删除 {$count} 条 {$days} 天以前的巡检记录。");

            return self::SUCCESS;
        }

        $deleted = $pruner->prune($days);
        $this->info("已删除 {$deleted} 条 {$days} 天以前的巡检记录。");

        return self::SUCCESS;
    }

    private function daysOption(): ?int
    {
        $value = $this->option('days');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('巡检历史保留天数必须是数字。');
        }

        return (int) $value;
    }
}
