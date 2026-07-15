<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class AdminAuditPruneService
{
    public const DEFAULT_RETENTION_DAYS = 180;
    public const MIN_RETENTION_DAYS = 30;
    public const MAX_RETENTION_DAYS = 3650;

    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '审计日志保留天数必须在 %d 到 %d 天之间。',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS,
            ));
        }

        return $days;
    }

    public function cutoffForDays(int $days): CarbonInterface
    {
        return now()->subDays($days);
    }

    public function countPrunable(int $days): int
    {
        return AdminAuditLog::query()
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->count();
    }

    public function prune(int $days): int
    {
        return AdminAuditLog::query()
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->delete();
    }
}
