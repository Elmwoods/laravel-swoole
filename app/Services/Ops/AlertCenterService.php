<?php

namespace App\Services\Ops;

use App\DTO\Ops\AlertDTO;
use App\Events\Ops\AlertTriggered;
use App\Models\OpsAlert;
use App\Services\Ops\Docker\DockerService;
use App\Services\Ops\System\DiskService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
     * 通知通道配置状态。
     */
    public function notificationStatus(): array
    {
        return $this->notification->status();
    }

    /**
     * 执行一次告警评估。
     */
    public function evaluate(): array
    {
        $snapshot = $this->snapshot();
        $detected = $this->ruleEngine->detect($snapshot);
        $alerts = [];
        $detectedFingerprints = collect($detected)
            ->map(fn (AlertDTO $dto): string => $dto->fingerprint())
            ->all();

        foreach ($detected as $dto) {
            [$alert, $shouldRepeatNotification] = $this->storeAlert($dto);
            $alerts[] = $alert;

            if ($alert->wasRecentlyCreated || $shouldRepeatNotification) {
                $this->notification->send($alert);
            }

            broadcast(new AlertTriggered($alert));
        }

        $resolvedAlerts = $this->autoResolveRecoveredAlerts($detectedFingerprints);

        foreach ($resolvedAlerts as $alert) {
            broadcast(new AlertTriggered($alert));
        }

        return [
            'detected' => count($detected),
            'alerts' => collect($alerts)->map(fn (OpsAlert $alert): array => $this->serialize($alert))->all(),
            'auto_resolved' => $resolvedAlerts->count(),
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
     * 生成告警中心演示数据。
     *
     * 该方法只写入轻量告警摘要，用固定 fingerprint 幂等更新同一批演示告警，
     * 便于本地和测试环境快速展示“触发 -> 确认 -> 恢复”的真实处理流程。
     */
    public function demoScenarios(): array
    {
        if (! (bool) config('ops.alerts.demo.enabled', true)) {
            return [
                'enabled' => false,
                'created' => 0,
                'items' => [],
                'summary' => $this->summary(),
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        $now = now();
        $scenarios = [
            [
                'fingerprint' => 'demo:disk-critical',
                'source' => 'disk',
                'severity' => 'critical',
                'title' => '模拟：磁盘使用率超过 95%',
                'message' => '生产目录 /var/www/html 当前使用率 96%，需要立即清理日志或扩容磁盘。',
                'context' => [
                    'is_demo' => true,
                    'step' => '1. 规则命中并触发严重告警',
                    'target' => '/var/www/html',
                    'usage' => 96,
                    'threshold' => 95,
                ],
                'status' => 'open',
                'last_seen_at' => $now->copy()->subMinutes(1),
                'acknowledged_at' => null,
                'acknowledged_by' => null,
                'acknowledge_note' => null,
            ],
            [
                'fingerprint' => 'demo:queue-warning',
                'source' => 'queue',
                'severity' => 'warning',
                'title' => '模拟：default 队列堆积',
                'message' => 'default 队列待处理任务 128 个，建议检查 queue worker 与下游服务响应。',
                'context' => [
                    'is_demo' => true,
                    'step' => '2. 业务队列出现积压',
                    'queue' => 'default',
                    'pending_jobs' => 128,
                    'threshold' => 100,
                ],
                'status' => 'open',
                'last_seen_at' => $now->copy()->subMinutes(3),
                'acknowledged_at' => null,
                'acknowledged_by' => null,
                'acknowledge_note' => null,
            ],
            [
                'fingerprint' => 'demo:docker-acknowledged',
                'source' => 'docker',
                'severity' => 'warning',
                'title' => '模拟：Octane 容器重启中',
                'message' => 'laravel.test 容器出现短暂异常，值班人员已确认并正在观察 Octane reload 状态。',
                'context' => [
                    'is_demo' => true,
                    'step' => '3. 值班人员确认处理中',
                    'container' => 'laravel.test',
                    'action' => 'octane reload',
                ],
                'status' => 'acknowledged',
                'last_seen_at' => $now->copy()->subMinutes(8),
                'acknowledged_at' => $now->copy()->subMinutes(6),
                'acknowledged_by' => 'demo-operator',
                'acknowledge_note' => '模拟流程：已通知值班同学处理，观察容器恢复情况。',
            ],
            [
                'fingerprint' => 'demo:network-resolved',
                'source' => 'network',
                'severity' => 'info',
                'title' => '模拟：网络流量恢复',
                'message' => '出口流量已回落到正常范围，关联告警已完成恢复闭环。',
                'context' => [
                    'is_demo' => true,
                    'step' => '4. 指标恢复并关闭告警',
                    'rx_mb_s' => 4.2,
                    'tx_mb_s' => 3.8,
                ],
                'status' => 'resolved',
                'last_seen_at' => $now->copy()->subMinutes(15),
                'acknowledged_at' => $now->copy()->subMinutes(10),
                'acknowledged_by' => 'demo-operator',
                'acknowledge_note' => '模拟流程：指标恢复后关闭，保留历史用于审计。',
            ],
        ];

        $alerts = collect($scenarios)->map(function (array $scenario): OpsAlert {
            $alert = OpsAlert::query()->firstOrNew([
                'fingerprint' => $scenario['fingerprint'],
            ]);

            $alert->fill($scenario);
            $alert->hit_count = $alert->exists ? $alert->hit_count + 1 : 1;
            $alert->save();

            broadcast(new AlertTriggered($alert));

            return $alert->refresh();
        });

        return [
            'enabled' => true,
            'created' => $alerts->count(),
            'items' => $alerts->map(fn (OpsAlert $alert): array => $this->serialize($alert))->values()->all(),
            'summary' => $this->summary(),
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
    private function storeAlert(AlertDTO $dto): array
    {
        $data = $dto->toArray();
        $alert = OpsAlert::query()->firstOrNew([
            'fingerprint' => $data['fingerprint'],
        ]);
        $shouldRepeatNotification = $alert->exists && $this->shouldRepeatNotification($alert);

        $alert->fill($data);
        $alert->status = 'open';
        $alert->last_seen_at = now();
        $alert->hit_count = $alert->exists ? $alert->hit_count + 1 : 1;
        $alert->save();

        return [$alert, $shouldRepeatNotification];
    }

    /**
     * 判断是否需要重复通知。
     *
     * 同一告警持续存在时不每次刷屏，只按配置的冷却时间重复通知。
     */
    private function shouldRepeatNotification(OpsAlert $alert): bool
    {
        $minutes = max(0, (int) config('ops.alerts.thresholds.notification_repeat_minutes', 30));

        if ($minutes === 0 || $alert->wasRecentlyCreated) {
            return false;
        }

        $updatedAt = $alert->updated_at;

        return $updatedAt !== null && $updatedAt->lte(now()->subMinutes($minutes));
    }

    /**
     * 自动恢复已经不再命中的告警。
     *
     * 为避免短暂采集失败误关闭，只有超过宽限时间仍未命中的 open / acknowledged
     * 告警才会被标记为 resolved。
     */
    private function autoResolveRecoveredAlerts(array $detectedFingerprints): Collection
    {
        if (! (bool) config('ops.alerts.thresholds.auto_resolve_enabled', true)) {
            return collect();
        }

        $graceMinutes = max(1, (int) config('ops.alerts.thresholds.auto_resolve_grace_minutes', 5));
        $managedSources = ['disk', 'queue', 'docker', 'network'];

        return OpsAlert::query()
            ->whereIn('source', $managedSources)
            ->whereIn('status', ['open', 'acknowledged'])
            ->where('last_seen_at', '<=', now()->subMinutes($graceMinutes))
            ->when(
                $detectedFingerprints !== [],
                fn ($query) => $query->whereNotIn('fingerprint', $detectedFingerprints),
            )
            ->get()
            ->map(function (OpsAlert $alert): OpsAlert {
                $alert->forceFill([
                    'status' => 'resolved',
                    'acknowledged_at' => $alert->acknowledged_at ?: now(),
                    'acknowledged_by' => $alert->acknowledged_by ?: 'ops-auto-resolver',
                    'acknowledge_note' => '规则恢复后自动关闭',
                ])->save();

                return $alert->refresh();
            });
    }
}
