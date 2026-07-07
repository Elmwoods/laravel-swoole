<?php

namespace App\Services\Ops;

use App\DTO\Ops\AlertDTO;
use App\Events\Ops\AlertTriggered;
use App\Models\OpsAlert;
use App\Services\Ops\Docker\DockerService;
use App\Services\Ops\System\DiskService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Ops Center 告警中心服务。
 *
 * 负责采集轻量指标、执行规则、持久化告警、发送通知和广播摘要。
 */
class AlertCenterService
{
    public function __construct(
        private readonly AlertRuleEngineService $ruleEngine,
        private readonly AlertNotificationService $notification,
        private readonly DiskService $diskService,
        private readonly QueueMonitorService $queueService,
        private readonly DockerService $dockerService,
        private readonly NetworkTrafficService $networkService,
    ) {}

    /**
     * 告警列表。
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return OpsAlert::query()
            ->when(($filters['status'] ?? '') !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['severity'] ?? '') !== '', fn ($query) => $query->where('severity', $filters['severity']))
            ->when(($filters['source'] ?? '') !== '', fn ($query) => $query->where('source', $filters['source']))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(5, min((int) ($filters['per_page'] ?? 20), 100)),
                page: max(1, (int) ($filters['page'] ?? 1)),
            );
    }

    /**
     * 告警汇总。
     */
    public function summary(): array
    {
        $open = OpsAlert::query()->where('status', 'open');

        return [
            'open_total' => (clone $open)->count(),
            'critical' => (clone $open)->where('severity', 'critical')->count(),
            'warning' => (clone $open)->where('severity', 'warning')->count(),
            'info' => (clone $open)->where('severity', 'info')->count(),
            'sources' => (clone $open)
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->orderByDesc('total')
                ->get()
                ->map(fn (object $row): array => [
                    'source' => $row->source,
                    'total' => (int) $row->total,
                ])
                ->all(),
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 执行一次告警评估。
     */
    public function evaluate(): array
    {
        $snapshot = $this->snapshot();
        $detected = $this->ruleEngine->detect($snapshot);
        $alerts = [];

        foreach ($detected as $dto) {
            $alert = $this->storeAlert($dto);
            $alerts[] = $alert;

            if ($alert->wasRecentlyCreated) {
                $this->notification->send($alert);
            }

            broadcast(new AlertTriggered($alert));
        }

        return [
            'detected' => count($detected),
            'alerts' => collect($alerts)->map(fn (OpsAlert $alert): array => $this->serialize($alert))->all(),
            'summary' => $this->summary(),
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 测试告警通知通道。
     */
    public function testNotification(array $payload): array
    {
        $result = $this->notification->sendTest(
            channels: $payload['channels'] ?? [],
            message: $payload['message'] ?? null,
        );

        return [
            'result' => $result,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 确认告警。
     */
    public function acknowledge(OpsAlert $alert, array $payload): OpsAlert
    {
        $alert->forceFill([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $payload['acknowledged_by'] ?? 'ops-user',
            'acknowledge_note' => $payload['note'] ?? null,
        ])->save();

        return $alert->refresh();
    }

    /**
     * 标记告警已恢复。
     *
     * 已恢复告警会从 open 统计中移除，但仍保留历史记录，便于后续审计。
     */
    public function resolve(OpsAlert $alert, array $payload): OpsAlert
    {
        $alert->forceFill([
            'status' => 'resolved',
            'acknowledged_at' => $alert->acknowledged_at ?: now(),
            'acknowledged_by' => $payload['acknowledged_by'] ?? $alert->acknowledged_by ?? 'ops-user',
            'acknowledge_note' => $payload['note'] ?? $alert->acknowledge_note,
        ])->save();

        return $alert->refresh();
    }

    /**
     * API 输出结构。
     */
    public function serialize(OpsAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'source' => $alert->source,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'message' => $alert->message,
            'context' => $alert->context ?? [],
            'status' => $alert->status,
            'hit_count' => $alert->hit_count,
            'last_seen_at' => optional($alert->last_seen_at)->toDateTimeString(),
            'acknowledged_at' => optional($alert->acknowledged_at)->toDateTimeString(),
            'acknowledged_by' => $alert->acknowledged_by,
            'acknowledge_note' => $alert->acknowledge_note,
            'created_at' => optional($alert->created_at)->toDateTimeString(),
            'updated_at' => optional($alert->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * 采集告警所需快照。
     */
    private function snapshot(): array
    {
        return [
            'disk' => $this->diskService->summary(),
            'queue' => $this->queueService->summary(),
            'docker' => $this->dockerService->summary(),
            'network' => $this->networkService->getSpeed(),
        ];
    }

    /**
     * 保存或更新同类告警。
     */
    private function storeAlert(AlertDTO $dto): OpsAlert
    {
        $data = $dto->toArray();
        $alert = OpsAlert::query()->firstOrNew([
            'fingerprint' => $data['fingerprint'],
        ]);

        $alert->fill($data);
        $alert->status = 'open';
        $alert->last_seen_at = now();
        $alert->hit_count = $alert->exists ? $alert->hit_count + 1 : 1;
        $alert->save();

        return $alert;
    }
}
