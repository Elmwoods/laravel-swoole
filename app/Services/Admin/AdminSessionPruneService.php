<?php

namespace App\Services\Admin;

use App\Models\AdminSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * 活跃会话注册表清理服务。
 *
 * 会话在空闲超时（120 分钟）后已不可用；本服务按最近活动时间清理 admin_sessions
 * 陈旧/已撤销行，防止无限增长。已撤销行的 last_activity_at 停止刷新，会自然老化清除。
 */
class AdminSessionPruneService
{
    // 默认保留天数
    public const DEFAULT_RETENTION_DAYS = 30;

    // 允许的最小保留天数（下限护栏，防止误删过近的会话）
    public const MIN_RETENTION_DAYS = 7;

    // 允许的最大保留天数（约 10 年，上限护栏）
    public const MAX_RETENTION_DAYS = 3650;

    /**
     * 作用：校验并归一化保留天数，越界即抛异常。
     *
     * @param  int|null  $days  期望保留天数，null 取默认值
     * @return int 合法范围内的保留天数
     *
     * 为什么：保留天数常来自外部输入（如运维配置），设上下限护栏防止 0 天（清空）
     * 或异常大值等误操作。
     */
    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        // 强制落在 [MIN, MAX] 区间内
        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '会话保留天数必须在 %d 到 %d 天之间。',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS,
            ));
        }

        return $days;
    }

    /**
     * 作用：把保留天数换算为截止时间点（早于此点者视为陈旧）。
     *
     * @param  int  $days  保留天数
     * @return CarbonInterface 当前时间往前推 $days 天的时间点
     */
    public function cutoffForDays(int $days): CarbonInterface
    {
        return now()->subDays($days);
    }

    /**
     * 作用：统计将被清理的会话行数（不实际删除）。
     *
     * @param  int  $days  保留天数
     * @return int 可清理的行数
     *
     * 为什么：清理前先给出预估数量，便于确认后再执行删除。
     */
    public function countPrunable(int $days): int
    {
        return $this->prunableQuery($days)->count();
    }

    /**
     * 作用：删除陈旧的会话行，返回删除数量。
     *
     * @param  int  $days  保留天数
     * @return int 实际删除的行数
     */
    public function prune(int $days): int
    {
        return $this->prunableQuery($days)->delete();
    }

    /**
     * 作用：构造「可清理会话」的查询——最近活动早于截止点，或从无活动记录。
     *
     * @param  int  $days  保留天数
     * @return Builder 已附加筛选条件的查询构造器
     *
     * 为什么：把两个条件包在闭包里用 AND 挂到主查询，保证 OR 的作用域被正确括起；
     * 已撤销会话的 last_activity_at 不再刷新，会随时间自然落入截止点之前被清除；
     * last_activity_at 为 null 的历史脏数据也一并纳入清理。
     */
    private function prunableQuery(int $days)
    {
        $cutoff = $this->cutoffForDays($days);

        return AdminSession::query()
            // 用闭包包裹 OR 条件，避免与外层其他条件的优先级混淆
            ->where(fn ($query) => $query
                ->where('last_activity_at', '<', $cutoff)
                ->orWhereNull('last_activity_at'));
    }
}
