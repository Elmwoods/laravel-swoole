<?php

namespace App\Services\Ops;

use App\Models\OpsMetricSample;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * 系统指标采样保留清理服务（分钟级采样，1440/天，需定期清理）。
 */
class OpsMetricSamplePruneService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public const MIN_RETENTION_DAYS = 7;

    public const MAX_RETENTION_DAYS = 3650;

    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '系统指标采样保留天数必须在 %d 到 %d 天之间。',
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
        return OpsMetricSample::query()
            ->where('captured_at', '<', $this->cutoffForDays($days))
            ->count();
    }

    public function prune(int $days): int
    {
        return OpsMetricSample::query()
            ->where('captured_at', '<', $this->cutoffForDays($days))
            ->delete();
    }
}
