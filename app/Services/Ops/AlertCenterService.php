<?php

namespace App\Services\Ops;

use App\DTO\Ops\AlertDTO;
use App\Events\Ops\AlertTriggered;
use App\Models\AdminLoginEvent;
use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsAlertEvaluation;
use App\Models\OpsAlertEvent;
use App\Models\OpsAlertSetting;
use App\Models\OpsInspection;
use App\Services\Ops\Docker\DockerService;
use App\Services\Ops\System\DiskService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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
        private readonly SystemMonitorService $systemService,
        private readonly RedisService $redisService,
        private readonly MysqlService $mysqlService,
        private readonly OctaneControlService $octaneService,
        private readonly SupervisorService $supervisorService,
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
     * 聚合最近 $hours 小时的告警（按严重级 / 状态 / 来源计数），供 digest 使用。
     */
    public function digestSummary(int $hours): array
    {
        $hours = max(1, min(168, $hours));
        $since = now()->subHours($hours);
        $scope = OpsAlert::query()->where('created_at', '>=', $since);

        $countBy = fn (string $column): array => (clone $scope)
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->pluck('total', $column)
            ->map(fn ($total): int => (int) $total)
            ->all();

        $bySeverity = $countBy('severity');
        $byStatus = $countBy('status');

        return [
            'window_hours' => $hours,
            'since' => $since->toDateTimeString(),
            'generated_at' => now()->toDateTimeString(),
            'total' => (clone $scope)->count(),
            'by_severity' => [
                'critical' => $bySeverity['critical'] ?? 0,
                'warning' => $bySeverity['warning'] ?? 0,
                'info' => $bySeverity['info'] ?? 0,
            ],
            'by_status' => [
                'open' => $byStatus['open'] ?? 0,
                'acknowledged' => $byStatus['acknowledged'] ?? 0,
                'resolved' => $byStatus['resolved'] ?? 0,
            ],
            'sources' => (clone $scope)
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn (object $row): array => [
                    'source' => (string) $row->source,
                    'total' => (int) $row->total,
                ])
                ->all(),
        ];
    }

    /**
     * 把聚合结果渲染成纯文本摘要正文。
     */
    public function renderDigest(array $summary): string
    {
        $sev = $summary['by_severity'];
        $status = $summary['by_status'];

        $sources = collect($summary['sources'])
            ->map(fn (array $row): string => "{$row['source']} {$row['total']}")
            ->implode(' / ');

        $lines = [
            "过去 {$summary['window_hours']} 小时告警摘要（{$summary['generated_at']}）",
            "共 {$summary['total']} 条（严重 {$sev['critical']} / 警告 {$sev['warning']} / 提示 {$sev['info']}）",
            "状态：待处理 {$status['open']} / 已确认 {$status['acknowledged']} / 已恢复 {$status['resolved']}",
            'Top 来源：'.($sources !== '' ? $sources : '无'),
        ];

        return $this->safeInspectionText(implode(PHP_EOL, $lines));
    }

    /**
     * 构造一条不落库的合成告警，把窗口摘要经现有通道推送。
     *
     * config 未开或窗口内无告警（且未配置空发）时不发送。
     */
    public function sendDigest(int $hours): array
    {
        if (! (bool) config('ops.alerts.digest.enabled', false)) {
            return ['sent' => false, 'reason' => 'disabled'];
        }

        $summary = $this->digestSummary($hours);

        if ($summary['total'] === 0 && ! (bool) config('ops.alerts.digest.send_when_empty', false)) {
            return ['sent' => false, 'reason' => 'empty', 'summary' => $summary];
        }

        $alert = new OpsAlert([
            'source' => 'digest',
            'severity' => (string) config('ops.alerts.digest.severity', 'info'),
            'title' => 'Ops Center 告警摘要',
            'message' => $this->renderDigest($summary),
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        return [
            'sent' => true,
            'summary' => $summary,
            'channels' => $this->notification->send($alert),
        ];
    }

    /**
     * 通知通道配置状态。
     */
    public function notificationStatus(): array
    {
        return [
            ...$this->notification->status(),
            'settings' => $this->settings(),
        ];
    }

    /**
     * 执行一次告警评估。
     */
    public function evaluate(string $trigger = 'manual'): array
    {
        $startedAt = now();
        $started = microtime(true);

        try {
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

            $result = [
                'detected' => count($detected),
                'alerts' => collect($alerts)->map(fn (OpsAlert $alert): array => $this->serialize($alert))->all(),
                'auto_resolved' => $resolvedAlerts->count(),
                'summary' => $this->summary(),
                'checked_at' => now()->toDateTimeString(),
            ];

            $this->recordEvaluation(
                trigger: $trigger,
                status: 'success',
                startedAt: $startedAt,
                started: $started,
                detected: $result['detected'],
                autoResolved: $result['auto_resolved'],
            );

            return $result;
        } catch (Throwable $e) {
            $this->recordEvaluation(
                trigger: $trigger,
                status: 'failure',
                startedAt: $startedAt,
                started: $started,
                message: $this->safeExceptionMessage($e),
            );

            throw new HttpException(500, '告警评估失败，请检查采集服务状态。');
        }
    }

    public function latestEvaluation(): ?array
    {
        $evaluation = OpsAlertEvaluation::query()->latest('id')->first();

        return $evaluation === null ? null : $this->serializeEvaluation($evaluation);
    }

    /**
     * 按天聚合告警评估趋势（近 $days 天，零填充连续日期）。
     */
    public function evaluationTrend(int $days): array
    {
        $days = max(1, min(90, $days));
        $since = now()->startOfDay()->subDays($days - 1);

        $rows = OpsAlertEvaluation::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as evaluations')
            ->selectRaw('SUM(detected_count) as detected')
            ->selectRaw('SUM(auto_resolved_count) as auto_resolved')
            ->selectRaw('AVG(duration_ms) as avg_duration_ms')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy('date');

        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $row = $rows->get($date);

            $buckets[] = [
                'date' => $date,
                'evaluations' => (int) ($row->evaluations ?? 0),
                'detected' => (int) ($row->detected ?? 0),
                'auto_resolved' => (int) ($row->auto_resolved ?? 0),
                'avg_duration_ms' => (int) round((float) ($row->avg_duration_ms ?? 0)),
            ];
        }

        return $buckets;
    }

    public function settings(): array
    {
        return OpsAlertSetting::allValues();
    }

    public function updateSettings(array $payload): array
    {
        $keys = [
            'notification_repeat_minutes',
            'auto_resolve_enabled',
            'auto_resolve_grace_minutes',
            'escalation_enabled',
            'escalation_after_minutes',
            'severity_channels',
        ];

        foreach ((array) config('ops.alerts.channels', ['telegram', 'mail']) as $channel) {
            $keys[] = "{$channel}_enabled";
        }

        foreach ($keys as $key) {
            OpsAlertSetting::setValue($key, $payload[$key]);
        }

        return $this->settings();
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
        $fromStatus = $alert->status;

        $alert->forceFill([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $payload['acknowledged_by'] ?? 'ops-user',
            'acknowledge_note' => $payload['note'] ?? null,
        ])->save();

        $alert = $alert->refresh();
        $this->recordAlertEvent($alert, 'acknowledged', $payload['acknowledged_by'] ?? 'ops-user', $payload['note'] ?? null, $fromStatus, $alert->status);

        return $alert;
    }

    public function assign(OpsAlert $alert, array $payload): OpsAlert
    {
        $alert->forceFill([
            'assigned_to' => $payload['assigned_to'],
            'assigned_at' => now(),
        ])->save();

        $alert = $alert->refresh();
        $this->recordAlertEvent($alert, 'assigned', $payload['assigned_to'], $payload['note'] ?? null, $alert->status, $alert->status);

        return $alert;
    }

    /**
     * 标记告警已恢复。
     *
     * 已恢复告警会从 open 统计中移除，但仍保留历史记录，便于后续审计。
     */
    public function resolve(OpsAlert $alert, array $payload): OpsAlert
    {
        $fromStatus = $alert->status;

        $alert->forceFill([
            'status' => 'resolved',
            'acknowledged_at' => $alert->acknowledged_at ?: now(),
            'acknowledged_by' => $payload['acknowledged_by'] ?? $alert->acknowledged_by ?? 'ops-user',
            'acknowledge_note' => $payload['note'] ?? $alert->acknowledge_note,
        ])->save();

        $alert = $alert->refresh();
        $this->recordAlertEvent($alert, 'resolved', $payload['acknowledged_by'] ?? $alert->acknowledged_by ?? 'ops-user', $payload['note'] ?? null, $fromStatus, $alert->status);

        return $alert;
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
            'assigned_to' => $alert->assigned_to,
            'assigned_at' => optional($alert->assigned_at)->toDateTimeString(),
            'escalated_at' => optional($alert->escalated_at)->toDateTimeString(),
            'timeline' => $this->timeline($alert),
            'created_at' => optional($alert->created_at)->toDateTimeString(),
            'updated_at' => optional($alert->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * 自动巡检失败时升起（或刷新）一条告警，并复用现有通知/广播闭环。
     *
     * 固定来源 inspection、固定指纹，重复失败只增加 hit_count，
     * 首次或超过冷却时间才重复推送通知，避免刷屏。
     */
    public function raiseInspectionAlert(OpsInspection $inspection): void
    {
        $failedChecks = collect($inspection->checks ?? [])
            ->where('status', 'fail')
            ->map(fn ($check): string => $this->safeInspectionText((string) ($check['name'] ?? '')))
            ->filter()
            ->values()
            ->all();

        $dto = new AlertDTO(
            source: 'inspection',
            severity: 'critical',
            title: '自动巡检失败',
            message: $this->safeInspectionText((string) ($inspection->failure_message ?: '自动巡检检测到失败项。')),
            context: [
                'target' => 'inspection',
                'inspection_id' => $inspection->id,
                'type' => $inspection->type,
                'trigger' => $inspection->trigger,
                'failed_checks' => $failedChecks,
            ],
        );

        [$alert, $shouldRepeatNotification] = $this->storeAlert($dto);

        if ($alert->wasRecentlyCreated || $shouldRepeatNotification) {
            $this->notification->send($alert);
        }

        $this->recordAlertEvent(
            $alert,
            $alert->wasRecentlyCreated ? 'inspection_failed' : 'inspection_refired',
            'ops-inspection',
            null,
            null,
            $alert->status,
            ['inspection_id' => $inspection->id],
        );

        broadcast(new AlertTriggered($alert));
    }

    /**
     * 管理员从新 IP / 新设备登录时升起一条安全告警，并复用现有通知/广播闭环。
     *
     * 首登（无历史）不告警；按 管理员+IP 去重，重复只增 hit_count；
     * 首次或超冷却时间才推送，避免刷屏。登录告警不进 autoResolveRecoveredAlerts
     * 托管源，需人工确认。
     */
    public function raiseLoginAnomalyAlert(AdminUser $admin, AdminLoginEvent $event): void
    {
        if (! (bool) config('ops.alerts.login_alerts.enabled', true)) {
            return;
        }

        if (! $event->is_new_ip && ! $event->is_new_user_agent) {
            return;
        }

        // 首登抑制：该管理员没有更早的登录事件时，说明是首次登录（如绑定 2FA 后首登），不算异常。
        $hasPriorLogin = AdminLoginEvent::query()
            ->where('admin_user_id', $admin->id)
            ->where('id', '<', $event->id)
            ->exists();

        if (! $hasPriorLogin) {
            return;
        }

        $flags = collect([
            $event->is_new_ip ? '新 IP' : null,
            $event->is_new_user_agent ? '新设备' : null,
        ])->filter()->implode(' / ');

        $ip = (string) ($event->ip_address ?: '未知');

        $dto = new AlertDTO(
            source: 'security_login',
            severity: (string) config('ops.alerts.login_alerts.severity', 'warning'),
            title: '异地/新设备登录',
            message: $this->safeInspectionText(
                "管理员 {$admin->email} 从 {$flags} 登录（IP：{$ip}，UA：".((string) ($event->user_agent ?: '未知')).'）。'
            ),
            context: [
                'target' => "admin:{$admin->id}:ip:{$ip}",
                'admin_id' => $admin->id,
                'login_event_id' => $event->id,
                'ip' => $event->ip_address,
                'is_new_ip' => (bool) $event->is_new_ip,
                'is_new_user_agent' => (bool) $event->is_new_user_agent,
                'trusted' => (bool) $event->trusted,
            ],
        );

        [$alert, $shouldRepeatNotification] = $this->storeAlert($dto);

        if ($alert->wasRecentlyCreated || $shouldRepeatNotification) {
            $this->notification->send($alert);
        }

        $this->recordAlertEvent(
            $alert,
            $alert->wasRecentlyCreated ? 'new_ip_login' : 'new_ip_login_refired',
            'ops-security',
            null,
            null,
            $alert->status,
            ['admin_id' => $admin->id, 'login_event_id' => $event->id],
        );

        broadcast(new AlertTriggered($alert));
    }

    /**
     * 审计异常（失败登录暴增 / 敏感操作）升告警，复用现有通知/广播闭环。
     *
     * $key 作 fingerprint 去重锚点（失败登录按 subject、敏感操作按审计行 id）；
     * source=security_audit 不在 autoResolveRecoveredAlerts 托管源，留人工确认。
     */
    public function raiseAuditAnomalyAlert(string $key, string $severity, string $title, string $message, array $context = []): void
    {
        $dto = new AlertDTO(
            source: 'security_audit',
            severity: $severity,
            title: $title,
            message: $this->safeInspectionText($message),
            context: array_merge($context, ['target' => $key]),
        );

        [$alert, $shouldRepeatNotification] = $this->storeAlert($dto);

        if ($alert->wasRecentlyCreated || $shouldRepeatNotification) {
            $this->notification->send($alert);
        }

        $this->recordAlertEvent(
            $alert,
            $alert->wasRecentlyCreated ? 'audit_anomaly' : 'audit_anomaly_refired',
            'ops-security',
            null,
            null,
            $alert->status,
            $context,
        );

        broadcast(new AlertTriggered($alert));
    }

    /**
     * 来源 IP 自动封禁告警（滥用来源被临时拉黑时升起）。
     *
     * source=security_access 不在 autoResolveRecoveredAlerts 托管源，留人工确认。
     */
    public function raiseAutoBanAlert(string $ip, string $message, array $context = []): void
    {
        $dto = new AlertDTO(
            source: 'security_access',
            severity: 'warning',
            title: '来源 IP 自动封禁',
            message: $this->safeInspectionText($message),
            context: array_merge($context, ['target' => "autoban:ip:{$ip}"]),
        );

        [$alert, $shouldRepeatNotification] = $this->storeAlert($dto);

        if ($alert->wasRecentlyCreated || $shouldRepeatNotification) {
            $this->notification->send($alert);
        }

        $this->recordAlertEvent(
            $alert,
            $alert->wasRecentlyCreated ? 'ip_auto_banned' : 'ip_auto_ban_refired',
            'ops-security',
            null,
            null,
            $alert->status,
            $context,
        );

        broadcast(new AlertTriggered($alert));
    }

    /**
     * 巡检恢复后自动关闭仍处于 open/acknowledged 的巡检告警。
     */
    public function resolveInspectionAlert(): void
    {
        OpsAlert::query()
            ->where('source', 'inspection')
            ->whereIn('status', ['open', 'acknowledged'])
            ->get()
            ->each(function (OpsAlert $alert): void {
                $fromStatus = $alert->status;
                $alert->forceFill([
                    'status' => 'resolved',
                    'acknowledged_at' => $alert->acknowledged_at ?: now(),
                    'acknowledged_by' => $alert->acknowledged_by ?: 'ops-inspection',
                    'acknowledge_note' => '巡检恢复后自动关闭',
                ])->save();

                $alert = $alert->refresh();
                $this->recordAlertEvent($alert, 'inspection_recovered', 'ops-inspection', '巡检恢复后自动关闭', $fromStatus, $alert->status);
                broadcast(new AlertTriggered($alert));
            });
    }

    /**
     * 巡检告警文本二次脱敏（防止上游遗漏时把密钥写入告警或通知）。
     */
    private function safeInspectionText(string $text): string
    {
        $patterns = [
            '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*=\s*[^,\s;]+/iu',
            '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*:\s*[^,\s;]+/iu',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '$1=[FILTERED]', $text) ?? $text;
        }

        return mb_strimwidth($text, 0, 500, '...');
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
            'system' => $this->systemService->info(),
            'redis' => $this->redisService->info(),
            'mysql' => $this->mysqlService->info(),
            'octane' => $this->octaneService->status(),
            'supervisor' => $this->supervisorProcesses($this->supervisorService->status()),
        ];
    }

    private function supervisorProcesses(array $status): array
    {
        if (array_key_exists('services', $status) && is_array($status['services'])) {
            return $status['services'];
        }

        return $status;
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
        $minutes = max(0, (int) OpsAlertSetting::value('notification_repeat_minutes'));

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
    /**
     * 升级长期未确认的 critical 告警：定时重推（绕过冷却）+ 记 escalated 事件 + 广播。
     *
     * 超时判定用 created_at（唯一稳定的 open-since；last_seen_at/updated_at 会在 re-fire 刷新）。
     * escalated_at 作再升级间隔锚点，防每分钟重复升级。
     */
    public function escalateStaleAlerts(bool $dryRun = false): Collection
    {
        if (! (bool) OpsAlertSetting::value('escalation_enabled')) {
            return collect();
        }

        $after = max(1, (int) OpsAlertSetting::value('escalation_after_minutes'));
        $cutoff = now()->subMinutes($after);

        $alerts = OpsAlert::query()
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->where('created_at', '<=', $cutoff)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('escalated_at')->orWhere('escalated_at', '<=', $cutoff);
            })
            ->get();

        if ($dryRun) {
            return $alerts;
        }

        return $alerts->map(function (OpsAlert $alert) use ($after): OpsAlert {
            $unackedMinutes = optional($alert->created_at)->diffInMinutes(now()) ?? 0;

            $alert->forceFill(['escalated_at' => now()])->save();
            $alert = $alert->refresh();

            $this->notification->send($alert);

            $this->recordAlertEvent(
                $alert,
                'escalated',
                'ops-escalator',
                "未确认超过 {$after} 分钟，已升级重推。",
                $alert->status,
                $alert->status,
                ['unacked_minutes' => (int) $unackedMinutes, 'hit_count' => (int) $alert->hit_count],
            );

            broadcast(new AlertTriggered($alert));

            return $alert;
        });
    }

    private function autoResolveRecoveredAlerts(array $detectedFingerprints): Collection
    {
        if (! (bool) OpsAlertSetting::value('auto_resolve_enabled')) {
            return collect();
        }

        $graceMinutes = max(1, (int) OpsAlertSetting::value('auto_resolve_grace_minutes'));
        $managedSources = ['disk', 'queue', 'docker', 'network', 'system', 'redis', 'mysql', 'octane', 'supervisor'];

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
                $fromStatus = $alert->status;
                $alert->forceFill([
                    'status' => 'resolved',
                    'acknowledged_at' => $alert->acknowledged_at ?: now(),
                    'acknowledged_by' => $alert->acknowledged_by ?: 'ops-auto-resolver',
                    'acknowledge_note' => '规则恢复后自动关闭',
                ])->save();

                $alert = $alert->refresh();
                $this->recordAlertEvent($alert, 'auto_resolved', 'ops-auto-resolver', '规则恢复后自动关闭', $fromStatus, $alert->status);

                return $alert;
            });
    }

    private function recordEvaluation(
        string $trigger,
        string $status,
        mixed $startedAt,
        float $started,
        int $detected = 0,
        int $autoResolved = 0,
        ?string $message = null,
    ): void {
        OpsAlertEvaluation::query()->create([
            'trigger' => in_array($trigger, ['manual', 'cli', 'schedule'], true) ? $trigger : 'manual',
            'status' => $status,
            'detected_count' => $detected,
            'auto_resolved_count' => $autoResolved,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'message' => $message,
        ]);
    }

    private function serializeEvaluation(OpsAlertEvaluation $evaluation): array
    {
        return [
            'id' => $evaluation->id,
            'trigger' => $evaluation->trigger,
            'status' => $evaluation->status,
            'detected_count' => $evaluation->detected_count,
            'auto_resolved_count' => $evaluation->auto_resolved_count,
            'started_at' => optional($evaluation->started_at)->toDateTimeString(),
            'finished_at' => optional($evaluation->finished_at)->toDateTimeString(),
            'duration_ms' => $evaluation->duration_ms,
            'message' => $evaluation->message,
        ];
    }

    private function recordAlertEvent(
        OpsAlert $alert,
        string $action,
        ?string $actor,
        ?string $note,
        ?string $fromStatus,
        ?string $toStatus,
        array $metadata = [],
    ): void {
        OpsAlertEvent::query()->create([
            'alert_id' => $alert->id,
            'action' => $action,
            'actor' => $actor,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function timeline(OpsAlert $alert): array
    {
        return OpsAlertEvent::query()
            ->where('alert_id', $alert->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (OpsAlertEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'actor' => $event->actor,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'metadata' => $event->metadata ?? [],
                'created_at' => optional($event->created_at)->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function safeExceptionMessage(Throwable $e): string
    {
        $message = preg_replace('#(/[A-Za-z0-9._\\-]+){2,}#', '[path]', $e->getMessage()) ?: 'alert_evaluation_failed';

        return mb_substr($message, 0, 500);
    }
}
