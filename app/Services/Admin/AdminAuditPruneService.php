<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * 后台审计日志「保留期清理」服务。
 *
 * 属于后台审计子系统的运维端：按保留天数（retention）计算截止时间，把过老的
 * admin_audit_logs 统计 / 物理删除，控制审计表体积与合规留存窗口。保留天数经
 * normalizeRetentionDays 夹在 [MIN, MAX] 范围内，防误删或永久堆积。
 */
class AdminAuditPruneService
{
    /** 默认保留天数：未显式指定时使用（约半年）。 */
    public const DEFAULT_RETENTION_DAYS = 180;

    /** 保留天数下限：防止把窗口设得过短导致误删近期审计。 */
    public const MIN_RETENTION_DAYS = 30;

    /** 保留天数上限：约 10 年，防设成天文数字等于永不清理。 */
    public const MAX_RETENTION_DAYS = 3650;

    /**
     * 作用：把（可空的）保留天数归一化到合法区间，非法则抛异常。
     *
     * @param  int|null  $days  期望保留天数；为 null 时回退默认值。
     * @return int 合法范围内的保留天数。
     *
     * @throws InvalidArgumentException 当天数越出 [MIN, MAX] 时。
     */
    public function normalizeRetentionDays(?int $days): int
    {
        $days ??= self::DEFAULT_RETENTION_DAYS;

        // 越界即拒绝：既不能太短（误删）也不能太长（等于不清理）。
        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '审计日志保留天数必须在 %d 到 %d 天之间。',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS,
            ));
        }

        return $days;
    }

    /**
     * 作用：由保留天数换算出清理的时间分界点（早于此点的记录视为可清）。
     *
     * @param  int  $days  保留天数。
     * @return CarbonInterface 当前时间往前推 $days 天的时刻。
     */
    public function cutoffForDays(int $days): CarbonInterface
    {
        return now()->subDays($days);
    }

    /**
     * 作用：统计在给定保留天数下有多少条审计记录会被清理（不实际删除）。
     *
     * 「为什么」单独提供计数：供清理前预览 / 二次确认，避免直接删除的不可逆风险。
     *
     * @param  int  $days  保留天数。
     * @return int 早于截止点的可清理记录数。
     */
    public function countPrunable(int $days): int
    {
        return AdminAuditLog::query()
            // 截止点之前（created_at < cutoff）的记录才算过期可清。
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->count();
    }

    /**
     * 作用：真正删除早于截止点的审计记录。
     *
     * @param  int  $days  保留天数。
     * @return int 实际删除的行数。
     */
    public function prune(int $days): int
    {
        return AdminAuditLog::query()
            // 与 countPrunable 同一条件，保证「预览数=删除数」语义一致。
            ->where('created_at', '<', $this->cutoffForDays($days))
            ->delete();
    }
}
