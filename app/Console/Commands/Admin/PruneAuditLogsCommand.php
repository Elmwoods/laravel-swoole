<?php

namespace App\Console\Commands\Admin;

use App\Services\Admin\AdminAuditPruneService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'admin:audit-prune
        {--days= : 审计日志保留天数，默认 180，范围 30-3650}
        {--dry-run : 只统计将删除的日志数量，不实际删除}';

    protected $description = 'Prune old admin audit logs';

    public function handle(AdminAuditPruneService $pruner): int
    {
        try {
            $days = $pruner->normalizeRetentionDays($this->daysOption());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = $pruner->countPrunable($days);
            $this->info("将删除 {$count} 条 {$days} 天以前的审计日志。");

            return self::SUCCESS;
        }

        $deleted = $pruner->prune($days);
        $this->info("已删除 {$deleted} 条 {$days} 天以前的审计日志。");

        return self::SUCCESS;
    }

    private function daysOption(): ?int
    {
        $value = $this->option('days');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('审计日志保留天数必须是数字。');
        }

        return (int) $value;
    }
}
