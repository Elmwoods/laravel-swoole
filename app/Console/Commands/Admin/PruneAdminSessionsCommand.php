<?php

namespace App\Console\Commands\Admin;

use App\Services\Admin\AdminSessionPruneService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneAdminSessionsCommand extends Command
{
    protected $signature = 'admin:sessions:prune
        {--days= : 会话注册表保留天数，默认 30，范围 7-3650}
        {--dry-run : 只统计将删除的会话记录数量，不实际删除}';

    protected $description = 'Prune stale/revoked admin session registry rows';

    public function handle(AdminSessionPruneService $pruner): int
    {
        try {
            $days = $pruner->normalizeRetentionDays($this->daysOption());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = $pruner->countPrunable($days);
            $this->info("将删除 {$count} 条最近 {$days} 天无活动的会话记录。");

            return self::SUCCESS;
        }

        $deleted = $pruner->prune($days);
        $this->info("已删除 {$deleted} 条最近 {$days} 天无活动的会话记录。");

        return self::SUCCESS;
    }

    private function daysOption(): ?int
    {
        $value = $this->option('days');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('会话保留天数必须是数字。');
        }

        return (int) $value;
    }
}
