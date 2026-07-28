<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use Illuminate\Support\Facades\Schema;

/**
 * 值班仪表盘：把当前值班、未来班次、待处理告警与 SLA 快照聚成一屏，供交接班一眼看清。纯只读聚合。
 */
class OnCallDashboardService
{
    public function __construct(
        private readonly OnCallRotationService $rotation,
        private readonly AlertCenterService $alerts,
    ) {}

    public function overview(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $current = $this->rotation->currentOnCall();

        return [
            'generated_at' => now()->toDateTimeString(),
            'current_on_call' => $current,
            'upcoming_shifts' => $this->upcomingShifts(),
            'my_open_alerts' => $this->assignedOpen($current),
            'unassigned_open' => $this->unassignedOpen(),
            'sla' => $this->slaSnapshot($days),
        ];
    }

    /**
     * 未来将生效的班次（生效范围尚未开始的 active 班次，按开始时间升序）。
     */
    private function upcomingShifts(): array
    {
        if (! Schema::hasTable('ops_on_call_shifts')) {
            return [];
        }

        return OpsOnCallShift::query()
            ->where('is_active', true)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(fn (OpsOnCallShift $shift): array => [
                'id' => $shift->id,
                'assignee' => $shift->assignee,
                'label' => $shift->label,
                'starts_at' => optional($shift->starts_at)->toDateTimeString(),
                'ends_at' => optional($shift->ends_at)->toDateTimeString(),
                'recurrence' => (string) ($shift->recurrence ?? 'once'),
            ])
            ->all();
    }

    /**
     * 指定处理人的 open 告警计数 + 严重级分解。
     */
    private function assignedOpen(?string $assignee): array
    {
        $base = fn () => OpsAlert::query()->where('status', 'open')->where('assigned_to', $assignee);

        if ($assignee === null || ! Schema::hasTable('ops_alerts')) {
            return ['assignee' => $assignee, 'total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0];
        }

        return [
            'assignee' => $assignee,
            'total' => $base()->count(),
            'critical' => (clone $base())->where('severity', 'critical')->count(),
            'warning' => (clone $base())->where('severity', 'warning')->count(),
            'info' => (clone $base())->where('severity', 'info')->count(),
        ];
    }

    private function unassignedOpen(): int
    {
        if (! Schema::hasTable('ops_alerts')) {
            return 0;
        }

        return OpsAlert::query()->where('status', 'open')->whereNull('assigned_to')->count();
    }

    /**
     * SLA 快照：积压分桶 + 总体达标率 + 当前违约数。
     */
    private function slaSnapshot(int $days): array
    {
        $sla = $this->alerts->slaSummary($days);

        return [
            'window_days' => $days,
            'open_aging' => $sla['open_aging'] ?? ['under_1h' => 0, 'one_to_24h' => 0, 'over_24h' => 0],
            'ack_rate' => $sla['compliance']['ack']['overall']['rate'] ?? null,
            'resolve_rate' => $sla['compliance']['resolve']['overall']['rate'] ?? null,
            'open_breaches' => $sla['open_breaches'] ?? 0,
        ];
    }
}
