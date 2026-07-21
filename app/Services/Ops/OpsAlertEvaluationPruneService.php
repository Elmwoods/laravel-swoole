<?php

namespace App\Services\Ops;

use App\Models\OpsAlertEvaluation;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * 告警评估历史清理服务。
 *
 * 告警评估默认每分钟一条，增长最快，需要定期清理防止 ops_alert_evaluations 无限膨胀。
 */
class OpsAlertEvaluationPruneService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public const MIN_RETENTION_DAYS = 7;

    public const MAX_RETENTION_DAYS = 3650;

    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '告警评估历史保留天数必须在 %d 到 %d 天之间。',
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
        return OpsAlertEvaluation::query()
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->count();
    }

    public function prune(int $days): int
    {
        return OpsAlertEvaluation::query()
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->delete();
    }
}
