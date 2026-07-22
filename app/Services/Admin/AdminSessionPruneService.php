<?php

namespace App\Services\Admin;

use App\Models\AdminSession;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * 活跃会话注册表清理服务。
 *
 * 会话在空闲超时（120 分钟）后已不可用；本服务按最近活动时间清理 admin_sessions
 * 陈旧/已撤销行，防止无限增长。已撤销行的 last_activity_at 停止刷新，会自然老化清除。
 */
class AdminSessionPruneService
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public const MIN_RETENTION_DAYS = 7;

    public const MAX_RETENTION_DAYS = 3650;

    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '会话保留天数必须在 %d 到 %d 天之间。',
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
        return $this->prunableQuery($days)->count();
    }

    public function prune(int $days): int
    {
        return $this->prunableQuery($days)->delete();
    }

    private function prunableQuery(int $days)
    {
        $cutoff = $this->cutoffForDays($days);

        return AdminSession::query()
            ->where(fn ($query) => $query
                ->where('last_activity_at', '<', $cutoff)
                ->orWhereNull('last_activity_at'));
    }
}
