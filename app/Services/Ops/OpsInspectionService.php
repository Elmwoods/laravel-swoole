<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlertEvaluation;
use App\Models\OpsInspection;
use App\Services\Ops\Log\OpsLogErrorWatcherService;
use Throwable;

class OpsInspectionService
{
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'cookie',
        'private_key',
        'authorization',
        'api_key',
    ];

    private const SENSITIVE_PATTERNS = [
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*=\s*[^,\s;]+/iu',
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*:\s*[^,\s;]+/iu',
    ];

    public function __construct(
        private readonly OpsReleaseCheckService $releaseCheck,
        private readonly AlertCenterService $alerts,
        private readonly OpsLogErrorWatcherService $logWatcher,
        private readonly QueueMonitorService $queues,
    ) {}

    public function run(string $type = 'light', string $trigger = 'schedule', ?AdminUser $admin = null): OpsInspection
    {
        $type = in_array($type, ['light', 'full'], true) ? $type : 'light';
        $trigger = in_array($trigger, ['manual', 'schedule'], true) ? $trigger : 'schedule';
        $startedAt = now();
        $started = microtime(true);
        $checks = [];

        if ($type === 'full') {
            $checks = array_merge($checks, $this->runReleaseCheck());
        }

        $checks = array_merge(
            $checks,
            $this->runAlertEvaluation(),
            $this->runLogWatcher(),
            $this->runQueueHealth(),
        );

        $checks = $this->sanitize($checks);
        $summary = $this->summarize($checks);
        $status = $this->aggregateStatus($checks);
        $failureMessage = $status === 'fail'
            ? $this->firstFailureMessage($checks)
            : null;

        $inspection = OpsInspection::query()->create([
            'admin_user_id' => $admin?->id,
            'admin_email' => $admin?->email,
            'type' => $type,
            'trigger' => $trigger,
            'status' => $status,
            'summary' => $summary,
            'checks' => $checks,
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'started_at' => $startedAt,
            'finished_at' => now(),
            'failure_message' => $failureMessage,
        ]);

        if ($status === 'fail') {
            $this->alerts->raiseInspectionAlert($inspection);
        } else {
            $this->alerts->resolveInspectionAlert();
        }

        return $inspection;
    }

    /**
     * 按天聚合巡检结果趋势（近 $days 天，零填充连续日期）。
     */
    public function trend(int $days): array
    {
        $days = max(1, min(90, $days));
        $since = now()->startOfDay()->subDays($days - 1);

        $rows = OpsInspection::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw("SUM(CASE WHEN status = 'pass' THEN 1 ELSE 0 END) as pass")
            ->selectRaw("SUM(CASE WHEN status = 'warn' THEN 1 ELSE 0 END) as warn")
            ->selectRaw("SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as fail")
            ->selectRaw('COUNT(*) as total')
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
                'pass' => (int) ($row->pass ?? 0),
                'warn' => (int) ($row->warn ?? 0),
                'fail' => (int) ($row->fail ?? 0),
                'total' => (int) ($row->total ?? 0),
                'avg_duration_ms' => (int) round((float) ($row->avg_duration_ms ?? 0)),
            ];
        }

        return $buckets;
    }

    public function summary(): array
    {
        $latest = OpsInspection::query()->latest('id')->first();

        return [
            'latest' => $latest ? $this->serializeSummary($latest) : null,
            'summary' => $latest ? $this->normalizeSummary($latest->summary ?? []) : ['pass' => 0, 'warn' => 0, 'fail' => 0],
        ];
    }

    public function serializeSummary(OpsInspection $record): array
    {
        return [
            'id' => $record->id,
            'type' => $record->type,
            'trigger' => $record->trigger,
            'status' => $record->status,
            'summary' => $this->normalizeSummary($record->summary ?? []),
            'duration_ms' => $record->duration_ms,
            'started_at' => optional($record->started_at)->toDateTimeString(),
            'finished_at' => optional($record->finished_at)->toDateTimeString(),
            'failure_message' => $record->failure_message ? $this->safeText($record->failure_message) : null,
            'admin_user_id' => $record->admin_user_id,
            'admin_email' => $record->admin_email,
            'created_at' => optional($record->created_at)->toDateTimeString(),
        ];
    }

    public function serializeDetail(OpsInspection $record): array
    {
        return array_merge($this->serializeSummary($record), [
            'checks' => $this->sanitize($record->checks ?? []),
        ]);
    }

    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->safeText($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function runReleaseCheck(): array
    {
        try {
            $result = $this->releaseCheck->run();

            return collect($result['checks'] ?? [])
                ->map(fn (array $check): array => [
                    'group' => '发布自检',
                    'name' => (string) ($check['group'] ?? '').' / '.(string) ($check['name'] ?? 'Check'),
                    'status' => $this->normalizeStatus((string) ($check['status'] ?? 'fail')),
                    'message' => (string) ($check['message'] ?? ''),
                    'hint' => (string) ($check['hint'] ?? ''),
                ])
                ->all();
        } catch (Throwable $e) {
            return [$this->failedCheck('发布自检', 'Release Check', $e)];
        }
    }

    private function runAlertEvaluation(): array
    {
        try {
            $result = $this->alerts->evaluate('inspection');
            $detected = (int) ($result['detected'] ?? 0);

            return [[
                'group' => '告警中心',
                'name' => 'Alert Evaluation',
                'status' => $detected > 0 ? 'warn' : 'pass',
                'message' => "命中 {$detected} 条告警，自动恢复 ".(int) ($result['auto_resolved'] ?? 0).' 条。',
                'hint' => $detected > 0 ? '进入告警中心处理未恢复告警。' : '告警规则评估正常。',
            ]];
        } catch (Throwable $e) {
            return [$this->alertEvaluationFailureCheck($e)];
        }
    }

    /**
     * 告警评估抛错时的降级判定：单次/少量瞬时失败 → warn；连续失败达阈值 → fail。
     *
     * 依据每分钟 ops:alerts:evaluate 落库的 ops_alert_evaluations 历史统计连续失败数
     * （本次失败已由 evaluate() 的 catch 记为最新一行），避免一次部署抖动就推 critical。
     */
    private function alertEvaluationFailureCheck(Throwable $e): array
    {
        $threshold = max(1, (int) config('ops.inspections.alert_eval_fail_threshold', 3));

        $recent = OpsAlertEvaluation::query()
            ->orderByDesc('id')
            ->limit($threshold)
            ->pluck('status');

        $failStreak = 0;

        foreach ($recent as $status) {
            if ($status === 'failure') {
                $failStreak++;

                continue;
            }

            break;
        }

        $persistent = $failStreak >= $threshold;
        $message = $this->safeText($e->getMessage());

        return [
            'group' => '告警中心',
            'name' => 'Alert Evaluation',
            'status' => $persistent ? 'fail' : 'warn',
            'message' => $persistent
                ? $message
                : "告警评估瞬时失败，已降级为警告（连续失败 {$failStreak}/{$threshold} 次）：{$message}",
            'hint' => $persistent
                ? '告警评估连续失败，请检查采集依赖（redis/docker/supervisor）与是否部署后未 octane:reload。'
                : '单次评估失败多为瞬时抖动（部署后未 reload、依赖瞬断）；连续失败达阈值才升级为严重。',
        ];
    }

    private function runLogWatcher(): array
    {
        try {
            $result = $this->logWatcher->scan(dryRun: true);
            $enabled = (bool) ($result['enabled'] ?? true);
            $detected = (int) ($result['detected'] ?? 0);

            return [[
                'group' => '日志中心',
                'name' => 'Log Error Watcher',
                'status' => ! $enabled ? 'warn' : ($detected > 0 ? 'warn' : 'pass'),
                'message' => $enabled
                    ? '扫描 '.(int) ($result['scanned'] ?? 0)." 个来源，发现 {$detected} 条新错误。"
                    : '日志错误扫描未启用。',
                'hint' => $detected > 0 ? '进入日志中心查看错误来源。' : '保持日志来源白名单和错误级别配置。',
            ]];
        } catch (Throwable $e) {
            return [$this->failedCheck('日志中心', 'Log Error Watcher', $e)];
        }
    }

    private function runQueueHealth(): array
    {
        try {
            $summary = $this->queues->summary();
            $failedJobs = (int) data_get($summary, 'failed_jobs.count', 0);
            $workerRunning = (bool) data_get($summary, 'workers.running', false);
            $pending = collect((array) ($summary['queues'] ?? []))->sum(fn (array $queue): int => (int) ($queue['pending'] ?? 0));

            return [
                [
                    'group' => '队列/调度',
                    'name' => 'Queue Workers',
                    'status' => $workerRunning ? 'pass' : 'warn',
                    'message' => $workerRunning ? '队列 worker 正在运行。' : '未检测到队列 worker。',
                    'hint' => $workerRunning ? '确认 worker 随发布重启。' : '启动 queue worker 或检查 Supervisor 配置。',
                ],
                [
                    'group' => '队列/调度',
                    'name' => 'Failed Jobs',
                    'status' => $failedJobs > 0 ? 'warn' : 'pass',
                    'message' => "失败任务 {$failedJobs} 个，待处理 {$pending} 个。",
                    'hint' => $failedJobs > 0 ? '检查 failed_jobs 并重试或清理。' : '队列失败任务正常。',
                ],
            ];
        } catch (Throwable $e) {
            return [$this->failedCheck('队列/调度', 'Queue Health', $e)];
        }
    }

    private function failedCheck(string $group, string $name, Throwable $e): array
    {
        return [
            'group' => $group,
            'name' => $name,
            'status' => 'fail',
            'message' => $this->safeText($e->getMessage()),
            'hint' => '检查相关服务配置和运行状态后重新执行巡检。',
        ];
    }

    private function summarize(array $checks): array
    {
        return [
            'pass' => collect($checks)->where('status', 'pass')->count(),
            'warn' => collect($checks)->where('status', 'warn')->count(),
            'fail' => collect($checks)->where('status', 'fail')->count(),
        ];
    }

    private function normalizeSummary(array $summary): array
    {
        return [
            'pass' => max(0, (int) ($summary['pass'] ?? 0)),
            'warn' => max(0, (int) ($summary['warn'] ?? 0)),
            'fail' => max(0, (int) ($summary['fail'] ?? 0)),
        ];
    }

    private function aggregateStatus(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');

        if ($statuses->contains('fail')) {
            return 'fail';
        }

        if ($statuses->contains('warn')) {
            return 'warn';
        }

        return 'pass';
    }

    private function firstFailureMessage(array $checks): ?string
    {
        $message = collect($checks)->firstWhere('status', 'fail')['message'] ?? null;

        return is_string($message) ? $this->safeText($message) : null;
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['pass', 'warn', 'fail'], true) ? $status : 'fail';
    }

    private function safeText(string $text): string
    {
        $filtered = $text;

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $filtered = preg_replace($pattern, '$1=[FILTERED]', $filtered) ?? $filtered;
        }

        return mb_strimwidth($filtered, 0, 500, '...');
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
