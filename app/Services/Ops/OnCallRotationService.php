<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsOnCallShift;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * 值班排班：按绝对时间段窗口维护值班人，供新告警自动指派解析「当前值班人」。
 *
 * currentOnCall() 在告警创建路径（AlertCenterService::storeAlert）调用，boot-safe（表缺失/出错→null，绝不阻断告警）。
 */
class OnCallRotationService
{
    /**
     * 当前值班人（此刻生效、最近开始的班次）；无则 null。
     */
    public function currentOnCall(): ?string
    {
        try {
            if (! Schema::hasTable('ops_on_call_shifts')) {
                return null;
            }

            $now = now();

            $shift = OpsOnCallShift::query()
                ->where('is_active', true)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->first();

            return $shift?->assignee;
        } catch (Throwable) {
            return null;
        }
    }

    public function list(): array
    {
        $current = $this->currentOnCall();

        return OpsOnCallShift::query()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OpsOnCallShift $shift): array => $this->serialize($shift, $current))
            ->all();
    }

    public function create(array $data, ?AdminUser $actor = null): OpsOnCallShift
    {
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        if ($startsAt === null || $endsAt === null || strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
            throw new InvalidArgumentException('结束时间必须晚于开始时间。');
        }

        return OpsOnCallShift::query()->create([
            'assignee' => trim((string) $data['assignee']),
            'label' => isset($data['label']) && trim((string) $data['label']) !== '' ? trim((string) $data['label']) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
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

    private function serialize(OpsOnCallShift $shift, ?string $current): array
    {
        return [
            'id' => $shift->id,
            'assignee' => $shift->assignee,
            'label' => $shift->label,
            'starts_at' => optional($shift->starts_at)->toDateTimeString(),
            'ends_at' => optional($shift->ends_at)->toDateTimeString(),
            'is_active' => (bool) $shift->is_active,
            'current' => $shift->is_active
                && $shift->assignee === $current
                && $shift->starts_at !== null && $shift->starts_at->lte(now())
                && $shift->ends_at !== null && $shift->ends_at->gte(now()),
        ];
    }
}
