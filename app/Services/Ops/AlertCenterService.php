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
use App\Models\OpsAlertSilence;
use App\Models\OpsChannelHealth;
use App\Models\OpsInspection;
use App\Services\Ops\Docker\DockerService;
use App\Services\Ops\System\DiskService;
use Carbon\CarbonInterface;
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
        private readonly OnCallRotationService $onCall,
        private readonly AlertSilenceService $silences,
        private readonly AlertCorrelationService $correlation,
        private readonly OpsAlertNoteService $notes,
    ) {}

    /**
     * 相似告警：同来源、已恢复、非自身的历史告警（按恢复近因倒序），附各自处理备注。供加速排查。
     *
     * @return array<int, array<string, mixed>>
     */
    public function similarAlerts(OpsAlert $alert, int $limit = 5): array
    {
        return OpsAlert::query()
            ->where('source', $alert->source)
            ->where('id', '!=', $alert->id)
            ->where('status', 'resolved')
            ->orderByDesc('updated_at')
            ->limit(max(1, min(20, $limit)))
            ->get()
            ->map(fn (OpsAlert $other): array => [
                'id' => $other->id,
                'title' => $other->title,
                'severity' => $other->severity,
                'status' => $other->status,
                'last_seen_at' => optional($other->last_seen_at)->toDateTimeString(),
                'acknowledged_by' => $other->acknowledged_by,
                'acknowledge_note' => $other->acknowledge_note,
                'resolved_at' => optional($other->updated_at)->toDateTimeString(),
                'notes' => $this->notes->list($other),
            ])
            ->all();
    }

    private const BATCH_CAP = 500;

    /**
     * 对某分组（by=source|severity）的 open 告警批量执行 acknowledge|assign。
     *
     * @return array{op:string, by:string, group:string, affected:int, capped:bool}
     */
    public function batchByGroup(string $by, string $group, string $op, array $payload = []): array
    {
        if (! in_array($by, ['source', 'severity'], true)) {
            throw new \InvalidArgumentException('分组维度仅支持 source 或 severity。');
        }

        if (! in_array($op, ['acknowledge', 'assign'], true)) {
            throw new \InvalidArgumentException('批量操作仅支持 acknowledge 或 assign。');
        }

        $query = OpsAlert::query()->where('status', 'open')->where($by, $group);
        $total = (clone $query)->count();
        $alerts = $query->orderBy('id')->limit(self::BATCH_CAP)->get();

        foreach ($alerts as $alert) {
            if ($op === 'acknowledge') {
                $this->acknowledge($alert, $payload);
            } else {
                $this->assign($alert, $payload);
            }
        }

        return [
            'op' => $op,
            'by' => $by,
            'group' => $group,
            'affected' => $alerts->count(),
            'capped' => $total > self::BATCH_CAP,
        ];
    }

    /**
     * 为某分组创建一条静默窗口（复用 phase-29 静默 create）。
     */
    public function batchSilenceGroup(string $by, string $group, int $minutes, ?AdminUser $actor = null): OpsAlertSilence
    {
        if (! in_array($by, ['source', 'severity'], true)) {
            throw new \InvalidArgumentException('分组维度仅支持 source 或 severity。');
        }

        $minutes = max(1, min(1440, $minutes));

        return $this->silences->create([
            'label' => "批量静默 {$group}",
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addMinutes($minutes)->toDateTimeString(),
            'sources' => $by === 'source' ? [$group] : [],
            'severities' => $by === 'severity' ? [$group] : [],
        ], $actor);
    }

    /**
     * 告警列表。
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return OpsAlert::query()
            ->when(($filters['status'] ?? '') !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['severity'] ?? '') !== '', fn ($query) => $query->where('severity', $filters['severity']))
            ->when(($filters['source'] ?? '') !== '', fn ($query) => $query->where('source', $filters['source']))
            ->when(($filters['assigned_to'] ?? '') !== '', fn ($query) => $query->where('assigned_to', $filters['assigned_to']))
            ->when(($filters['assigned'] ?? '') === 'unassigned', fn ($query) => $query->whereNull('assigned_to'))
            ->when(($filters['tag'] ?? '') !== '', fn ($query) => $query->whereJsonContains('tags', $filters['tag']))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(5, min((int) ($filters['per_page'] ?? 20), 100)),
                page: max(1, (int) ($filters['page'] ?? 1)),
            );
    }

    /**
     * open 告警按指定维度分组聚合（只读，不改去重）。供分组视图降噪 / 关联。
     *
     * @param  string  $by  source|severity|assigned_to（非法回退 source）
     * @return array<int, array<string, mixed>>
     */
    public function groupedOpen(string $by = 'source'): array
    {
        $by = in_array($by, ['source', 'severity', 'assigned_to'], true) ? $by : 'source';

        return OpsAlert::query()
            ->where('status', 'open')
            ->orderByDesc('last_seen_at')
            ->get(['source', 'severity', 'title', 'assigned_to', 'last_seen_at'])
            ->groupBy(fn (OpsAlert $alert): string => (string) ($alert->{$by} ?? '') !== ''
                ? (string) $alert->{$by}
                : ($by === 'assigned_to' ? '__unassigned__' : '未知'))
            ->map(function (Collection $group, string $key) use ($by): array {
                return [
                    'group' => $key,
                    'by' => $by,
                    'total' => $group->count(),
                    'critical' => $group->where('severity', 'critical')->count(),
                    'warning' => $group->where('severity', 'warning')->count(),
                    'info' => $group->where('severity', 'info')->count(),
                    'assigned' => $group->filter(fn (OpsAlert $a): bool => (string) ($a->assigned_to ?? '') !== '')->count(),
                    'unassigned' => $group->filter(fn (OpsAlert $a): bool => (string) ($a->assigned_to ?? '') === '')->count(),
                    'last_seen_at' => optional($group->max('last_seen_at'))->toDateTimeString(),
                    'samples' => $group->take(3)->pluck('title')->values()->all(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * 已被指派过的处理人去重列表（供筛选下拉，不暴露完整管理员名册）。
     *
     * @return array<int, string>
     */
    public function assignees(): array
    {
        return OpsAlert::query()
            ->whereNotNull('assigned_to')
            ->where('assigned_to', '!=', '')
            ->distinct()
            ->orderBy('assigned_to')
            ->pluck('assigned_to')
            ->all();
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
     * 告警热力图：按 小时(0-23)×星期(0-6) 分桶的告警频率 + 最吵来源 + 按天趋势。纯只读，PHP 分桶（DB 可移植）。
     */
    public function heatmapSummary(int $days, bool $weighted = false): array
    {
        $days = max(1, min(90, $days));
        $since = now()->subDays($days);

        $alerts = OpsAlert::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'source', 'hit_count']);

        // 7×24 零矩阵（dow 0=周日..6=周六）。
        $matrix = [];
        foreach (range(0, 6) as $dow) {
            foreach (range(0, 23) as $hour) {
                $matrix[$dow][$hour] = 0;
            }
        }

        $trend = [];

        foreach ($alerts as $alert) {
            if ($alert->created_at === null) {
                continue;
            }

            $dow = (int) $alert->created_at->dayOfWeek;
            $hour = (int) $alert->created_at->hour;
            $weight = $weighted ? max(1, (int) $alert->hit_count) : 1;
            $matrix[$dow][$hour] += $weight;

            $date = $alert->created_at->toDateString();
            $trend[$date] = ($trend[$date] ?? 0) + $weight;
        }

        $buckets = [];
        foreach ($matrix as $dow => $hours) {
            foreach ($hours as $hour => $count) {
                $buckets[] = ['dow' => $dow, 'hour' => $hour, 'count' => $count];
            }
        }

        ksort($trend);

        return [
            'window_days' => $days,
            'generated_at' => now()->toDateTimeString(),
            'weighted' => $weighted,
            'buckets' => $buckets,
            'sources' => OpsAlert::query()
                ->where('created_at', '>=', $since)
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn (object $row): array => ['source' => (string) $row->source, 'total' => (int) $row->total])
                ->all(),
            'trend' => collect($trend)->map(fn (int $count, string $date): array => ['date' => $date, 'count' => $count])->values()->all(),
        ];
    }

    /**
     * 告警统计周报聚合：告警摘要 + SLA 快照 + 值班，供定时推送 / GET 端点。
     */
    public function weeklyReportSummary(int $days): array
    {
        $days = max(1, min(90, $days));
        $digest = $this->digestSummary(min(168, $days * 24));
        $sla = $this->slaSummary($days);

        return [
            'window_days' => $days,
            'generated_at' => now()->toDateTimeString(),
            'alerts' => [
                'total' => $digest['total'],
                'by_severity' => $digest['by_severity'],
                'by_status' => $digest['by_status'],
                'sources' => $digest['sources'],
            ],
            'sla' => [
                'mtta_avg_seconds' => $sla['mtta']['avg_seconds'] ?? 0,
                'mttr_avg_seconds' => $sla['mttr']['avg_seconds'] ?? 0,
                'ack_rate' => $sla['compliance']['ack']['overall']['rate'] ?? null,
                'resolve_rate' => $sla['compliance']['resolve']['overall']['rate'] ?? null,
                'open_aging' => $sla['open_aging'] ?? ['under_1h' => 0, 'one_to_24h' => 0, 'over_24h' => 0],
                'open_breaches' => $sla['open_breaches'] ?? 0,
            ],
            'on_call' => [
                'current' => $this->onCall->currentOnCall(),
            ],
        ];
    }

    public function renderWeeklyReport(array $report): string
    {
        $a = $report['alerts'];
        $sev = $a['by_severity'];
        $sla = $report['sla'];
        $sources = collect($a['sources'])->map(fn (array $r): string => "{$r['source']} {$r['total']}")->implode(' / ');

        $lines = [
            "Ops Center 告警周报（近 {$report['window_days']} 天，{$report['generated_at']}）",
            "告警共 {$a['total']} 条（严重 {$sev['critical']} / 警告 {$sev['warning']} / 提示 {$sev['info']}）",
            'Top 来源：'.($sources !== '' ? $sources : '无'),
            'SLA：MTTA '.round(($sla['mtta_avg_seconds'] ?? 0) / 60).' 分 / MTTR '.round(($sla['mttr_avg_seconds'] ?? 0) / 60).' 分',
            '达标率：确认 '.($sla['ack_rate'] ?? '无').'% / 恢复 '.($sla['resolve_rate'] ?? '无').'%；当前违约 '.$sla['open_breaches'],
            '当前值班：'.($report['on_call']['current'] ?? '无'),
        ];

        return $this->safeInspectionText(implode(PHP_EOL, $lines));
    }

    public function sendWeeklyReport(int $days): array
    {
        if (! (bool) config('ops.alerts.weekly_report.enabled', false)) {
            return ['sent' => false, 'reason' => 'disabled'];
        }

        $report = $this->weeklyReportSummary($days);

        if ($report['alerts']['total'] === 0 && ! (bool) config('ops.alerts.weekly_report.send_when_empty', false)) {
            return ['sent' => false, 'reason' => 'empty', 'report' => $report];
        }

        $alert = new OpsAlert([
            'source' => 'weekly-report',
            'severity' => (string) config('ops.alerts.weekly_report.severity', 'info'),
            'title' => 'Ops Center 告警周报',
            'message' => $this->renderWeeklyReport($report),
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        return [
            'sent' => true,
            'report' => $report,
            'channels' => $this->notification->send($alert),
        ];
    }

    /**
     * 通知通道配置状态。
     */
    public function notificationStatus(): array
    {
        return [
            ...$this->mergeChannelHealth($this->notification->status()),
            'settings' => $this->settings(),
        ];
    }

    /**
     * 把每通道健康态（ops_channel_health）合并进通知状态的对应通道对象。
     */
    private function mergeChannelHealth(array $status): array
    {
        try {
            $health = OpsChannelHealth::query()->get()->keyBy('channel');
        } catch (Throwable) {
            return $status;
        }

        foreach ($status as $channel => &$item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $health->get($channel);
            $item['health'] = $row?->status ?? 'unknown';
            $item['consecutive_failures'] = (int) ($row?->consecutive_failures ?? 0);
            $item['last_checked_at'] = optional($row?->last_checked_at)->toDateTimeString();
            $item['last_error'] = $row?->last_error;
        }

        return $status;
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

    /**
     * 告警处理 SLA 统计（MTTA/MTTR + 按来源/严重级聚合 + 趋势 + 当前积压分桶）。
     *
     * 起点锚固定 ops_alerts.created_at（re-fire 保留首次发生时间）；确认/恢复时间取 ops_alert_events
     * 事件的 created_at（比被回填的 acknowledged_at 列 / 不稳定的 updated_at 干净）。时长在 PHP 计算（DB 可移植）。
     */
    public function slaSummary(int $days): array
    {
        $days = max(1, min(90, $days));
        $since = now()->subDays($days);

        $ackPairs = $this->slaPairs(['acknowledged'], $since);
        $resolvePairs = $this->slaPairs(
            ['resolved', 'auto_resolved', 'channel_health_recovered', 'inspection_recovered'],
            $since,
        );

        return [
            'window_days' => $days,
            'generated_at' => now()->toDateTimeString(),
            'mtta' => $this->durationStats(array_column($ackPairs, 'seconds')),
            'mttr' => $this->durationStats(array_column($resolvePairs, 'seconds')),
            'by_source' => $this->slaBySource($ackPairs, $resolvePairs),
            'by_severity' => $this->slaBySeverity($resolvePairs),
            'trend' => $this->slaTrend($resolvePairs),
            'open_aging' => $this->openAging(),
            'targets' => [
                'enabled' => (bool) config('ops.alerts.sla.enabled', false),
                'ack_minutes' => $this->slaTargets('ack_minutes'),
                'resolve_minutes' => $this->slaTargets('resolve_minutes'),
            ],
            'compliance' => [
                'ack' => $this->slaCompliance($ackPairs, 'ack_minutes'),
                'resolve' => $this->slaCompliance($resolvePairs, 'resolve_minutes'),
            ],
            'open_breaches' => OpsAlert::query()->where('source', 'sla_breach')->where('status', 'open')->count(),
        ];
    }

    /**
     * SLA 达标率：按严重级统计实际时长 <= 该级目标（分钟*60 秒）的比例。
     * rate = total>0 ? round(within/total*100) : null（无数据不计率）。
     *
     * @param  array<int, array{severity: string, seconds: int}>  $pairs
     * @return array<string, array{within: int, total: int, rate: int|null}>
     */
    private function slaCompliance(array $pairs, string $targetKey): array
    {
        $targets = $this->slaTargets($targetKey);
        $by = collect($pairs)->groupBy('severity');

        $tally = function (Collection $group, int $targetSeconds): array {
            $total = $group->count();
            $within = $group->filter(fn (array $pair): bool => $pair['seconds'] <= $targetSeconds)->count();

            return [
                'within' => $within,
                'total' => $total,
                'rate' => $total > 0 ? (int) round($within / $total * 100) : null,
            ];
        };

        $result = [];
        $overall = collect();
        $overallWithin = 0;

        foreach (['critical', 'warning', 'info'] as $severity) {
            $group = $by->get($severity, collect());
            $targetSeconds = ($targets[$severity] ?? 1) * 60;
            $stats = $tally($group, $targetSeconds);
            $result[$severity] = $stats;

            $overall = $overall->merge($group);
            $overallWithin += $stats['within'];
        }

        $overallTotal = $overall->count();
        $result['overall'] = [
            'within' => $overallWithin,
            'total' => $overallTotal,
            'rate' => $overallTotal > 0 ? (int) round($overallWithin / $overallTotal * 100) : null,
        ];

        return $result;
    }

    /**
     * 取「事件 → 告警」配对，每个告警取窗口内最早的匹配事件，算 born→事件的秒数。
     *
     * @return array<int, array{alert_id: int, source: string, severity: string, at: string, seconds: int}>
     */
    private function slaPairs(array $actions, CarbonInterface $since): array
    {
        $rows = DB::table('ops_alert_events as e')
            ->join('ops_alerts as a', 'a.id', '=', 'e.alert_id')
            ->whereIn('e.action', $actions)
            ->where('e.created_at', '>=', $since)
            ->orderBy('e.created_at')
            ->get(['e.alert_id', 'a.source', 'a.severity', 'a.created_at as born', 'e.created_at as at']);

        $pairs = [];

        foreach ($rows as $row) {
            $id = (int) $row->alert_id;

            if (isset($pairs[$id])) {
                continue; // 已取该告警最早事件（结果已按事件时间升序）
            }

            $seconds = max(0, strtotime((string) $row->at) - strtotime((string) $row->born));

            $pairs[$id] = [
                'alert_id' => $id,
                'source' => (string) $row->source,
                'severity' => (string) $row->severity,
                'at' => (string) $row->at,
                'seconds' => $seconds,
            ];
        }

        return array_values($pairs);
    }

    /**
     * @param  array<int, int>  $seconds
     * @return array{count: int, avg_seconds: int, max_seconds: int}
     */
    private function durationStats(array $seconds): array
    {
        if ($seconds === []) {
            return ['count' => 0, 'avg_seconds' => 0, 'max_seconds' => 0];
        }

        return [
            'count' => count($seconds),
            'avg_seconds' => (int) round(array_sum($seconds) / count($seconds)),
            'max_seconds' => max($seconds),
        ];
    }

    private function slaBySource(array $ackPairs, array $resolvePairs): array
    {
        $ackBy = collect($ackPairs)->groupBy('source');
        $resolveBy = collect($resolvePairs)->groupBy('source');
        $sources = $ackBy->keys()->merge($resolveBy->keys())->unique()->values();

        return $sources
            ->map(function (string $source) use ($ackBy, $resolveBy): array {
                $ack = $this->durationStats($ackBy->get($source, collect())->pluck('seconds')->all());
                $resolve = $this->durationStats($resolveBy->get($source, collect())->pluck('seconds')->all());

                return [
                    'source' => $source,
                    'mtta_avg_seconds' => $ack['avg_seconds'],
                    'mtta_count' => $ack['count'],
                    'mttr_avg_seconds' => $resolve['avg_seconds'],
                    'mttr_count' => $resolve['count'],
                ];
            })
            ->sortByDesc('mttr_avg_seconds')
            ->values()
            ->all();
    }

    private function slaBySeverity(array $resolvePairs): array
    {
        $by = collect($resolvePairs)->groupBy('severity');

        $result = [];
        foreach (['critical', 'warning', 'info'] as $severity) {
            $stats = $this->durationStats($by->get($severity, collect())->pluck('seconds')->all());
            $result[$severity] = [
                'mttr_avg_seconds' => $stats['avg_seconds'],
                'mttr_count' => $stats['count'],
            ];
        }

        return $result;
    }

    private function slaTrend(array $resolvePairs): array
    {
        return collect($resolvePairs)
            ->groupBy(fn (array $pair): string => substr($pair['at'], 0, 10))
            ->map(fn ($group, string $date): array => [
                'date' => $date,
                'mttr_avg_seconds' => (int) round($group->avg('seconds')),
                'resolved_count' => $group->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    private function openAging(): array
    {
        $open = OpsAlert::query()->where('status', 'open')->get(['created_at']);

        $buckets = ['under_1h' => 0, 'one_to_24h' => 0, 'over_24h' => 0];

        foreach ($open as $alert) {
            $ageMinutes = optional($alert->created_at)->diffInMinutes(now()) ?? 0;

            if ($ageMinutes < 60) {
                $buckets['under_1h']++;
            } elseif ($ageMinutes < 1440) {
                $buckets['one_to_24h']++;
            } else {
                $buckets['over_24h']++;
            }
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

        // message_template（全局）+ 每通道模板走独立可选守卫（不在全 required 的 $keys 循环内），
        // 既保留旧设置 payload 的兼容性，又能持久化自定义模板。
        if (array_key_exists('message_template', $payload)) {
            OpsAlertSetting::setValue('message_template', (string) $payload['message_template']);
        }

        foreach (OpsAlertSetting::textChannels() as $channel) {
            $key = "message_template_{$channel}";
            if (array_key_exists($key, $payload)) {
                OpsAlertSetting::setValue($key, (string) $payload[$key]);
            }
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

    /**
     * 覆盖告警标签（去重规范化）并记事件。
     */
    public function setTags(OpsAlert $alert, array $tags): OpsAlert
    {
        $clean = array_values(array_unique(array_filter(
            array_map(fn ($t): string => trim((string) $t), $tags),
            fn (string $t): bool => $t !== '',
        )));

        $alert->forceFill(['tags' => $clean])->save();
        $alert = $alert->refresh();
        $this->recordAlertEvent($alert, 'tags_updated', 'ops-user', implode(', ', $clean), $alert->status, $alert->status);

        return $alert;
    }

    public function assign(OpsAlert $alert, array $payload): OpsAlert
    {
        $alert = $this->markAssigned($alert, $payload['assigned_to'], $payload['assigned_to'], $payload['note'] ?? null);

        // 手动指派 → 推送指派通知（config 未开时该方法内部 no-op）。自动指派路径不发（见 storeAlert）。
        $this->notification->sendAssignment($alert, $payload['assigned_to']);

        return $alert;
    }

    /**
     * 设置告警的指派人并记录 assigned 事件（不含通知），供手动指派与值班自动指派共用。
     */
    private function markAssigned(OpsAlert $alert, string $person, string $actor, ?string $note): OpsAlert
    {
        $alert->forceFill([
            'assigned_to' => $person,
            'assigned_at' => now(),
        ])->save();

        $alert = $alert->refresh();
        $this->recordAlertEvent($alert, 'assigned', $actor, $note, $alert->status, $alert->status);

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
            'tags' => $alert->tags ?? [],
            'status' => $alert->status,
            'hit_count' => $alert->hit_count,
            'last_seen_at' => optional($alert->last_seen_at)->toDateTimeString(),
            'acknowledged_at' => optional($alert->acknowledged_at)->toDateTimeString(),
            'acknowledged_by' => $alert->acknowledged_by,
            'acknowledge_note' => $alert->acknowledge_note,
            'assigned_to' => $alert->assigned_to,
            'assigned_at' => optional($alert->assigned_at)->toDateTimeString(),
            'escalated_at' => optional($alert->escalated_at)->toDateTimeString(),
            'escalation_level' => (int) $alert->escalation_level,
            'suppressed_at' => optional($alert->suppressed_at)->toDateTimeString(),
            'flap_count' => (int) $alert->flap_count,
            'flapping_until' => optional($alert->flapping_until)->toDateTimeString(),
            'runbook' => $this->runbookFor($alert->source),
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
     * 解析 SLA 时限 csv（顺序 critical,warning,info）为 per-severity 分钟 map。
     *
     * @return array{critical: int, warning: int, info: int}
     */
    public function slaTargets(string $key): array
    {
        $raw = (string) config("ops.alerts.sla.{$key}", '');
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v): bool => $v !== ''));
        $fallback = $key === 'ack_minutes' ? [10, 30, 120] : [60, 240, 1440];

        return [
            'critical' => max(1, (int) ($parts[0] ?? $fallback[0])),
            'warning' => max(1, (int) ($parts[1] ?? $fallback[1])),
            'info' => max(1, (int) ($parts[2] ?? $fallback[2])),
        ];
    }

    /**
     * 定时扫描未闭环告警，按严重级的确认/恢复时限判 SLA 违约，命中升 sla_breach 治理告警。
     *
     * 排除 sla_breach/digest 源（防自我递归 + 不对摘要计 SLA）；起点锚 created_at（稳定）。
     */
    public function scanSlaBreaches(bool $dryRun = false): Collection
    {
        if (! (bool) config('ops.alerts.sla.enabled', false)) {
            return collect();
        }

        $ack = $this->slaTargets('ack_minutes');
        $resolve = $this->slaTargets('resolve_minutes');
        $now = now();

        $alerts = OpsAlert::query()
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereNotIn('source', ['sla_breach', 'digest'])
            ->get();

        $breached = collect();

        foreach ($alerts as $alert) {
            $severity = in_array($alert->severity, ['critical', 'warning', 'info'], true) ? $alert->severity : 'warning';
            $ageMinutes = (int) (optional($alert->created_at)->diffInMinutes($now) ?? 0);

            $kinds = [];
            if ($alert->status === 'open' && $ageMinutes >= $ack[$severity]) {
                $kinds[] = 'ack';
            }
            if ($ageMinutes >= $resolve[$severity]) {
                $kinds[] = 'resolve';
            }

            if ($kinds === []) {
                continue;
            }

            $breached->push($alert);

            if (! $dryRun) {
                $this->raiseSlaBreachAlert($alert, $kinds, $ageMinutes, $severity, $kinds === ['ack'] ? $ack[$severity] : $resolve[$severity]);
            }
        }

        if (! $dryRun) {
            $this->resolveClearedSlaBreaches();
        }

        return $breached;
    }

    /**
     * 升起一条 SLA 违约治理告警（每个被违约的原告警一条，指纹去重 + 冷却）。source=sla_breach。
     *
     * @param  array<int, string>  $kinds
     */
    public function raiseSlaBreachAlert(OpsAlert $alert, array $kinds, int $ageMinutes, string $severity, int $targetMinutes): void
    {
        $kindText = in_array('ack', $kinds, true) && in_array('resolve', $kinds, true)
            ? '未确认且未恢复'
            : (in_array('ack', $kinds, true) ? '未确认' : '未恢复');

        $message = "{$alert->source}/{$alert->title} 已 open {$ageMinutes} 分钟{$kindText}，超出 {$severity} SLA 目标（{$targetMinutes} 分钟）。";

        $dto = new AlertDTO(
            source: 'sla_breach',
            severity: 'warning',
            title: '告警处理 SLA 违约',
            message: $this->safeInspectionText($message),
            context: [
                'target' => "sla_breach:{$alert->id}",
                'original_id' => $alert->id,
                'original_source' => $alert->source,
                'kinds' => $kinds,
                'age_minutes' => $ageMinutes,
            ],
        );

        [$breach, $shouldRepeatNotification] = $this->storeAlert($dto);

        if ($breach->wasRecentlyCreated || $shouldRepeatNotification) {
            $this->notification->send($breach);
        }

        $this->recordAlertEvent(
            $breach,
            $breach->wasRecentlyCreated ? 'sla_breach' : 'sla_breach_refired',
            'ops-sla',
            null,
            null,
            $breach->status,
            ['original_id' => $alert->id, 'kinds' => $kinds],
        );

        broadcast(new AlertTriggered($breach));
    }

    /**
     * 原告警已恢复后，关闭仍 open/acknowledged 的对应 sla_breach 治理告警。
     */
    public function resolveClearedSlaBreaches(): void
    {
        $breaches = OpsAlert::query()
            ->where('source', 'sla_breach')
            ->whereIn('status', ['open', 'acknowledged'])
            ->get();

        foreach ($breaches as $breach) {
            $originalId = (int) data_get($breach->context, 'original_id', 0);
            if ($originalId <= 0) {
                continue;
            }

            $original = OpsAlert::query()->find($originalId);
            if ($original !== null && $original->status !== 'resolved') {
                continue; // 原告警仍未闭环，保留违约告警
            }

            $fromStatus = $breach->status;
            $breach->forceFill([
                'status' => 'resolved',
                'acknowledged_at' => $breach->acknowledged_at ?: now(),
                'acknowledged_by' => $breach->acknowledged_by ?: 'ops-sla',
                'acknowledge_note' => '原告警已恢复，SLA 违约自动关闭',
            ])->save();

            $breach = $breach->refresh();
            $this->recordAlertEvent($breach, 'sla_breach_cleared', 'ops-sla', '原告警已恢复，SLA 违约自动关闭', $fromStatus, $breach->status);
            broadcast(new AlertTriggered($breach));
        }
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
     * 通知通道连通性自检失败告警（某通道连续失败超阈值时升起）。
     *
     * source=channel_health 不在 autoResolveRecoveredAlerts 托管源，恢复走 resolveChannelHealthAlert。
     */
    public function raiseChannelHealthAlert(string $channel, string $message, array $context = []): void
    {
        $dto = new AlertDTO(
            source: 'channel_health',
            severity: 'warning',
            title: '通知通道连通性异常',
            message: $this->safeInspectionText($message),
            context: array_merge($context, ['target' => "channel_health:{$channel}"]),
        );

        [$alert, $shouldRepeatNotification] = $this->storeAlert($dto);

        if ($alert->wasRecentlyCreated || $shouldRepeatNotification) {
            $this->notification->send($alert);
        }

        $this->recordAlertEvent(
            $alert,
            $alert->wasRecentlyCreated ? 'channel_health_failed' : 'channel_health_refired',
            'ops-health',
            null,
            null,
            $alert->status,
            $context,
        );

        broadcast(new AlertTriggered($alert));
    }

    /**
     * 某通道连通性恢复后，关闭仍 open/acknowledged 的对应 channel_health 告警。
     */
    public function resolveChannelHealthAlert(string $channel): void
    {
        OpsAlert::query()
            ->where('source', 'channel_health')
            ->where('context->target', "channel_health:{$channel}")
            ->whereIn('status', ['open', 'acknowledged'])
            ->get()
            ->each(function (OpsAlert $alert): void {
                $fromStatus = $alert->status;
                $alert->forceFill([
                    'status' => 'resolved',
                    'acknowledged_at' => $alert->acknowledged_at ?: now(),
                    'acknowledged_by' => $alert->acknowledged_by ?: 'ops-health',
                    'acknowledge_note' => '通道连通性恢复后自动关闭',
                ])->save();

                $alert = $alert->refresh();
                $this->recordAlertEvent($alert, 'channel_health_recovered', 'ops-health', '通道连通性恢复后自动关闭', $fromStatus, $alert->status);
                broadcast(new AlertTriggered($alert));
            });
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
        // reopen 信号：已恢复的告警重新触发（fill 前捕获原始状态）。
        $wasReopen = $alert->exists && $alert->getOriginal('status') === 'resolved';

        $alert->fill($data);
        $alert->status = 'open';
        $alert->last_seen_at = now();
        $alert->hit_count = $alert->exists ? $alert->hit_count + 1 : 1;
        // 按来源自动打标（保留已有标签，去重）。
        $alert->tags = array_values(array_unique(array_merge(
            (array) $alert->tags,
            (array) config("ops.alerts.auto_tags.{$alert->source}", []),
        )));
        $alert->save();

        if ($wasReopen) {
            $this->registerReopen($alert);
        }

        // 关联抑制标记：父来源正在 firing 则标 suppressed_at（供 UI/审计），send() 亦会跳过外发。
        $alert->forceFill(['suppressed_at' => $this->correlation->isSuppressed($alert) ? now() : null])->save();

        // 值班自动指派：仅对刚新建、尚未指派的告警，且开启值班自动指派、当前有值班人时生效。
        // 自动指派不发指派通知（告警本体已外发，避免双重刷屏）。
        if ($alert->wasRecentlyCreated
            && $alert->assigned_to === null
            && (bool) config('ops.alerts.on_call.enabled', false)
            && ($person = $this->onCall->currentOnCall()) !== null) {
            $alert = $this->markAssigned($alert, $person, 'on-call-auto', '值班自动指派');
        }

        return [$alert, $shouldRepeatNotification];
    }

    /**
     * 记录一次告警重开（resolved→open），统计窗口内重开次数，超阈值则标记 flapping（冷却期抑制外发）。
     */
    public function registerReopen(OpsAlert $alert): void
    {
        $this->recordAlertEvent($alert, 'reopened', 'ops-flap-detector', '告警恢复后重新触发', 'resolved', $alert->status);

        $window = max(1, (int) config('ops.alerts.flapping.window_minutes', 30));
        $threshold = max(1, (int) config('ops.alerts.flapping.threshold', 3));
        $cooldown = max(1, (int) config('ops.alerts.flapping.cooldown_minutes', 30));

        $count = OpsAlertEvent::query()
            ->where('alert_id', $alert->id)
            ->where('action', 'reopened')
            ->where('created_at', '>=', now()->subMinutes($window))
            ->count();

        $flappingUntil = ((bool) config('ops.alerts.flapping.enabled', false) && $count >= $threshold)
            ? now()->addMinutes($cooldown)
            : $alert->flapping_until;

        $alert->forceFill([
            'flap_count' => $count,
            'flapping_until' => $flappingUntil,
        ])->save();
    }

    /**
     * 解析某来源的处理预案（config alerts.runbooks[source]）。无则 null。
     *
     * @return array{url: ?string, steps: array<int, string>}|null
     */
    private function runbookFor(?string $source): ?array
    {
        if ($source === null || $source === '') {
            return null;
        }

        $runbook = config("ops.alerts.runbooks.{$source}");

        if (! is_array($runbook)) {
            return null;
        }

        $steps = array_values(array_filter(
            (array) ($runbook['steps'] ?? []),
            fn ($s): bool => is_string($s) && $s !== '',
        ));

        $url = isset($runbook['url']) && is_string($runbook['url']) && $runbook['url'] !== '' ? $runbook['url'] : null;

        if ($url === null && $steps === []) {
            return null;
        }

        return ['url' => $url, 'steps' => $steps];
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

        $levels = $this->escalationLevels();

        if ($levels === []) {
            return collect();
        }

        $now = now();
        $firstAfter = $levels[0]['after_minutes'];

        // 只取够老（已达 L1 阈值）的 open critical。
        $candidates = OpsAlert::query()
            ->where('status', 'open')
            ->where('severity', 'critical')
            ->where('created_at', '<=', $now->copy()->subMinutes($firstAfter))
            ->get();

        // 计算每条的目标级别与是否需要动作（进阶升级 or 到点重推）。
        $due = $candidates
            ->map(function (OpsAlert $alert) use ($levels, $now): ?array {
                $age = (int) (optional($alert->created_at)->diffInMinutes($now) ?? 0);
                $target = $this->targetEscalationLevel($levels, $age);

                if ($target < 1) {
                    return null;
                }

                $current = (int) $alert->escalation_level;
                $interval = $levels[$target - 1]['after_minutes'];

                // 进阶到更高级别，或已在该级但重推间隔已到。
                if ($target > $current || $this->escalationReNotifyDue($alert, $interval, $now)) {
                    return ['alert' => $alert, 'level' => $target, 'age' => $age];
                }

                return null;
            })
            ->filter()
            ->values();

        if ($dryRun) {
            return $due->map(fn (array $row): OpsAlert => $row['alert'])->values();
        }

        return $due->map(function (array $row) use ($levels, $now): OpsAlert {
            /** @var OpsAlert $alert */
            $alert = $row['alert'];
            $level = $row['level'];
            $age = $row['age'];
            $config = $levels[$level - 1];

            $alert->forceFill(['escalated_at' => $now, 'escalation_level' => $level])->save();
            $alert = $alert->refresh();

            // 该级要求改派 → 重指派给当前值班人（若有）。
            if (! empty($config['reassign_on_call']) && ($person = $this->onCall->currentOnCall()) !== null) {
                $alert = $this->markAssigned($alert, $person, 'ops-escalator', "L{$level} 升级改派");
            }

            // 该级指定通道（空=全部启用通道）。
            $this->notification->send($alert, $config['channels'] ?: null);

            $this->recordAlertEvent(
                $alert,
                'escalated',
                'ops-escalator',
                "已升级至 L{$level}（未闭环约 {$age} 分钟）。",
                $alert->status,
                $alert->status,
                ['level' => $level, 'unacked_minutes' => $age, 'hit_count' => (int) $alert->hit_count],
            );

            broadcast(new AlertTriggered($alert));

            return $alert;
        });
    }

    /**
     * 解析多级升级配置（clamp + 按 after_minutes 升序）。回退：config 为空时用 escalation_after_minutes 设置构造单级。
     *
     * @return array<int, array{after_minutes: int, channels: array<int, string>, reassign_on_call: bool}>
     */
    private function escalationLevels(): array
    {
        $raw = (array) config('ops.alerts.escalation_levels', []);
        $levels = [];

        foreach ($raw as $level) {
            $levels[] = [
                'after_minutes' => max(1, (int) ($level['after_minutes'] ?? 0)),
                'channels' => array_values(array_filter(
                    (array) ($level['channels'] ?? []),
                    fn ($c): bool => is_string($c) && $c !== '',
                )),
                'reassign_on_call' => (bool) ($level['reassign_on_call'] ?? false),
            ];
        }

        if ($levels === []) {
            $levels[] = [
                'after_minutes' => max(1, (int) OpsAlertSetting::value('escalation_after_minutes')),
                'channels' => [],
                'reassign_on_call' => false,
            ];
        }

        usort($levels, fn (array $a, array $b): int => $a['after_minutes'] <=> $b['after_minutes']);

        return $levels;
    }

    /**
     * 达到（age >= after_minutes）的最高级别序号（1-based）；levels 已升序。
     *
     * @param  array<int, array{after_minutes: int}>  $levels
     */
    private function targetEscalationLevel(array $levels, int $age): int
    {
        $target = 0;

        foreach ($levels as $level) {
            if ($age >= $level['after_minutes']) {
                $target++;

                continue;
            }

            break;
        }

        return $target;
    }

    private function escalationReNotifyDue(OpsAlert $alert, int $intervalMinutes, CarbonInterface $now): bool
    {
        if ($alert->escalated_at === null) {
            return true;
        }

        return $alert->escalated_at->lte($now->copy()->subMinutes($intervalMinutes));
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
