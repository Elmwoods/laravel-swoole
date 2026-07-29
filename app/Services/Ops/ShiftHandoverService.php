<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsShiftHandover;
use InvalidArgumentException;

/**
 * 值班交接班记录：值班切换时记一条交出/接手 + 备注 + 当时 open 告警快照，形成值班日志。全局记录。
 */
class ShiftHandoverService
{
    public function __construct(
        private readonly OnCallRotationService $onCall,
        private readonly AlertCenterService $alerts,
    ) {}

    public function create(array $data, ?AdminUser $actor = null): OpsShiftHandover
    {
        $to = isset($data['to_assignee']) && trim((string) $data['to_assignee']) !== ''
            ? trim((string) $data['to_assignee'])
            : $this->onCall->currentOnCall();

        if ($to === null || $to === '') {
            throw new InvalidArgumentException('无法确定接手人：当前无值班人，请显式填写接手人。');
        }

        $from = isset($data['from_assignee']) && trim((string) $data['from_assignee']) !== ''
            ? trim((string) $data['from_assignee'])
            : optional(OpsShiftHandover::query()->latest('id')->first())->to_assignee;

        return OpsShiftHandover::query()->create([
            'from_assignee' => $from,
            'to_assignee' => $to,
            'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
            'open_alert_count' => (int) ($this->alerts->summary()['open_total'] ?? 0),
            'created_by' => $actor?->id,
        ]);
    }

    public function list(): array
    {
        return OpsShiftHandover::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (OpsShiftHandover $h): array => [
                'id' => $h->id,
                'from_assignee' => $h->from_assignee,
                'to_assignee' => $h->to_assignee,
                'note' => $h->note,
                'open_alert_count' => (int) $h->open_alert_count,
                'created_at' => optional($h->created_at)->toDateTimeString(),
            ])
            ->all();
    }
}
