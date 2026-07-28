<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsOnCallShift;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * 值班排班：维护值班班次（绝对时间段 / 每天 / 每周重复），供新告警自动指派解析「当前值班人」。
 *
 * currentOnCall() 在告警创建路径（AlertCenterService::storeAlert）调用，boot-safe（表缺失/出错→null，绝不阻断告警）。
 */
class OnCallRotationService
{
    private const RECURRENCES = ['once', 'daily', 'weekly'];

    public function __construct(
        private readonly AlertNotificationService $notification,
    ) {}

    /**
     * 向即将上岗（lead_minutes 内开始）的一次性班次值班人推送提醒，去重（reminded_at）。返回推送条数。
     */
    public function sendDueReminders(): int
    {
        try {
            if (! (bool) config('ops.alerts.on_call_reminder.enabled', false)) {
                return 0;
            }

            if (! Schema::hasTable('ops_on_call_shifts')) {
                return 0;
            }

            $lead = max(1, (int) config('ops.alerts.on_call_reminder.lead_minutes', 15));
            $now = now();

            // 仅 once 绝对班次（recurring 无法用单 reminded_at 按次去重）。
            $shifts = OpsOnCallShift::query()
                ->where('is_active', true)
                ->where('recurrence', 'once')
                ->whereNull('reminded_at')
                ->whereBetween('starts_at', [$now, $now->copy()->addMinutes($lead)])
                ->get();

            $sent = 0;

            foreach ($shifts as $shift) {
                $this->notification->sendOnCallReminder(
                    (string) $shift->assignee,
                    optional($shift->starts_at)->toDateTimeString() ?? '',
                );
                $shift->forceFill(['reminded_at' => now()])->save();
                $sent++;
            }

            return $sent;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 当前值班人（此刻生效、最近开始且命中重复模式的班次）；无则 null。
     */
    public function currentOnCall(): ?string
    {
        try {
            return $this->currentShift()?->assignee;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 当前生效班次（SQL 预筛生效范围，再按序取首个命中重复模式的班次）。
     */
    private function currentShift(): ?OpsOnCallShift
    {
        if (! Schema::hasTable('ops_on_call_shifts')) {
            return null;
        }

        $now = now();

        $shifts = OpsOnCallShift::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        foreach ($shifts as $shift) {
            if ($this->matchesRecurrence($shift, $now)) {
                return $shift;
            }
        }

        return null;
    }

    public function list(): array
    {
        $currentId = $this->safeCurrentShiftId();

        return OpsOnCallShift::query()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OpsOnCallShift $shift): array => $this->serialize($shift, $currentId))
            ->all();
    }

    public function create(array $data, ?AdminUser $actor = null): OpsOnCallShift
    {
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        if ($startsAt === null || $endsAt === null || strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
            throw new InvalidArgumentException('结束时间必须晚于开始时间。');
        }

        $recurrence = (string) ($data['recurrence'] ?? 'once');
        $recurrence = in_array($recurrence, self::RECURRENCES, true) ? $recurrence : 'once';
        $startTime = null;
        $endTime = null;
        $daysOfWeek = null;

        if ($recurrence !== 'once') {
            $startTime = (string) ($data['start_time'] ?? '');
            $endTime = (string) ($data['end_time'] ?? '');

            if ($startTime === '' || $endTime === '') {
                throw new InvalidArgumentException('周期性值班必须设置每天的开始与结束时刻。');
            }

            if ($recurrence === 'weekly') {
                $daysOfWeek = collect((array) ($data['days_of_week'] ?? []))
                    ->map(fn ($d): int => (int) $d)
                    ->filter(fn (int $d): bool => $d >= 0 && $d <= 6)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($daysOfWeek === []) {
                    throw new InvalidArgumentException('每周值班必须至少选择一天。');
                }
            }
        }

        return OpsOnCallShift::query()->create([
            'assignee' => trim((string) $data['assignee']),
            'label' => isset($data['label']) && trim((string) $data['label']) !== '' ? trim((string) $data['label']) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'recurrence' => $recurrence,
            'days_of_week' => $daysOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor?->id,
        ]);
    }

    public function toggle(int $id, bool $active): bool
    {
        return OpsOnCallShift::query()->whereKey($id)->update(['is_active' => $active]) > 0;
    }

    public function delete(int $id): bool
    {
        return OpsOnCallShift::query()->whereKey($id)->delete() > 0;
    }

    private function safeCurrentShiftId(): ?int
    {
        try {
            return $this->currentShift()?->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 在生效范围（starts_at–ends_at，SQL 已预筛）内，按重复模式判断此刻是否命中。
     */
    private function matchesRecurrence(OpsOnCallShift $shift, CarbonInterface $now): bool
    {
        $recurrence = (string) ($shift->recurrence ?? 'once');

        if ($recurrence === 'once') {
            return true; // 绝对窗口已由 SQL 预筛
        }

        if (! $this->timeInWindow($now, (string) $shift->start_time, (string) $shift->end_time)) {
            return false;
        }

        if ($recurrence === 'weekly') {
            $days = array_map('intval', (array) ($shift->days_of_week ?? []));

            return in_array((int) $now->dayOfWeek, $days, true);
        }

        return true; // daily
    }

    /**
     * 当前 H:i 是否落在 [start,end]（字典序）；start>end 视为跨午夜（t>=start 或 t<=end）。
     */
    private function timeInWindow(CarbonInterface $now, string $start, string $end): bool
    {
        if ($start === '' || $end === '') {
            return false;
        }

        $t = $now->format('H:i');

        return $start <= $end
            ? ($t >= $start && $t <= $end)
            : ($t >= $start || $t <= $end);
    }

    private function serialize(OpsOnCallShift $shift, ?int $currentId): array
    {
        return [
            'id' => $shift->id,
            'assignee' => $shift->assignee,
            'label' => $shift->label,
            'starts_at' => optional($shift->starts_at)->toDateTimeString(),
            'ends_at' => optional($shift->ends_at)->toDateTimeString(),
            'recurrence' => (string) ($shift->recurrence ?? 'once'),
            'days_of_week' => array_map('intval', (array) ($shift->days_of_week ?? [])),
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'is_active' => (bool) $shift->is_active,
            'current' => $shift->id === $currentId,
        ];
    }
}
