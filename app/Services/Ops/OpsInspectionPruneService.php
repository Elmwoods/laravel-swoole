<?php

namespace App\Services\Ops;

use App\Models\OpsInspection;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * 自动巡检历史清理服务。
 *
 * 巡检默认每 15 分钟一条，需要定期清理防止 ops_inspections 无限增长。
 */
class OpsInspectionPruneService
{
    public const DEFAULT_RETENTION_DAYS = 14;

    public const MIN_RETENTION_DAYS = 3;

    public const MAX_RETENTION_DAYS = 3650;

    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '巡检历史保留天数必须在 %d 到 %d 天之间。',
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
        return OpsInspection::query()
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->count();
    }

    public function prune(int $days): int
    {
        return OpsInspection::query()
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->delete();
    }
}
