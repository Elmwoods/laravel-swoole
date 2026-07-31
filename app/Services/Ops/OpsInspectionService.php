<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlertEvaluation;
use App\Models\OpsInspection;
use App\Services\Ops\Log\OpsLogErrorWatcherService;
use Throwable;

/**
 * 运维巡检服务：Ops Center 的“一键体检”入口。
 *
 * 作用：把发布自检、告警评估、日志错误监控、队列健康四类检查聚合成一次巡检，
 * 统一脱敏、汇总统计（pass/warn/fail）、落库为 OpsInspection 记录，并按结果
 * 联动告警中心（fail 则升告警、否则自动恢复）。
 *
 * 「为什么」：巡检既有定时（schedule）也有人工（manual）触发，各子检查都用 try/catch
 * 包裹，单个子系统异常只降级为一条 fail check，不会中断整次巡检；输出前全部经过
 * sanitize/safeText 脱敏，避免把密钥、token 等敏感串写进历史或推送给告警。
 */
class OpsInspectionService
{
    // 需要整段过滤的敏感字段名（命中即替换为 [FILTERED]）
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'cookie',
        'private_key',
        'authorization',
        'api_key',
    ];

    // 敏感文本正则：匹配 key=value / key:value 形式的密钥泄露，保留 key、把 value 替换为 [FILTERED]
    private const SENSITIVE_PATTERNS = [
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*=\s*[^,\s;]+/iu',
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*:\s*[^,\s;]+/iu',
    ];

    /**
     * 作用：注入巡检依赖的四个子系统服务（构造器属性提升，均为只读）。
     *
     * @param  OpsReleaseCheckService  $releaseCheck  发布自检（仅 full 巡检执行）
     * @param  AlertCenterService  $alerts  告警中心（评估告警 + 巡检结果联动告警）
     * @param  OpsLogErrorWatcherService  $logWatcher  日志错误监控
     * @param  QueueMonitorService  $queues  队列/worker 健康监控
     */
    public function __construct(
        private readonly OpsReleaseCheckService $releaseCheck,
        private readonly AlertCenterService $alerts,
        private readonly OpsLogErrorWatcherService $logWatcher,
        private readonly QueueMonitorService $queues,
    ) {}

    /**
     * 作用：执行一次巡检，聚合各子检查、脱敏、汇总、落库，并联动告警中心。
     *
     * @param  string  $type  巡检类型，light（快速）或 full（含发布自检）；非法值回落 light
     * @param  string  $trigger  触发来源，manual（人工）或 schedule（定时）；非法值回落 schedule
     * @param  AdminUser|null  $admin  触发人（定时触发为 null），用于记录操作者
     * @return OpsInspection 落库后的巡检记录
     *
     * 「为什么」：入参先白名单校验，避免外部传入非法枚举污染落库；full 才跑较重的发布自检，
     * light 只跑告警/日志/队列三项以便高频定时执行。
     */
    public function run(string $type = 'light', string $trigger = 'schedule', ?AdminUser $admin = null): OpsInspection
    {
        // 入参白名单化：非法枚举回落到默认值，保证落库字段可控
        $type = in_array($type, ['light', 'full'], true) ? $type : 'light';
        $trigger = in_array($trigger, ['manual', 'schedule'], true) ? $trigger : 'schedule';
        $startedAt = now();
        $started = microtime(true); // 高精度计时起点，用于统计 duration_ms
        $checks = [];

        // 仅 full 巡检才执行较重的发布自检
        if ($type === 'full') {
            $checks = array_merge($checks, $this->runReleaseCheck());
        }

        $checks = array_merge(
            $checks,
            $this->runAlertEvaluation(),
            $this->runLogWatcher(),
            $this->runQueueHealth(),
        );

        $checks = $this->sanitize($checks);          // 落库前统一脱敏
        $summary = $this->summarize($checks);        // 汇总 pass/warn/fail 计数
        $status = $this->aggregateStatus($checks);   // 取最严重状态作为整体结论
        // 只有整体 fail 才记录首条失败信息，供列表页快速展示原因
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
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)), // 耗时毫秒，钳到 >=0
            'started_at' => $startedAt,
            'finished_at' => now(),
            'failure_message' => $failureMessage,
        ]);

        // 结果联动告警中心：fail 升巡检告警，非 fail 自动恢复既有巡检告警
        if ($status === 'fail') {
            $this->alerts->raiseInspectionAlert($inspection);
        } else {
            $this->alerts->resolveInspectionAlert();
        }

        return $inspection;
    }

    /**
     * 按天聚合巡检结果趋势（近 $days 天，零填充连续日期）。
     *
     * 作用：统计每天各状态巡检次数与平均耗时，供前端画趋势图。
     *
     * @param  int  $days  统计天数，会被钳到 [1, 90]
     * @return array<int, array{date:string,pass:int,warn:int,fail:int,total:int,avg_duration_ms:int}> 按日期升序的桶
     *
     * 「为什么」：DB 分组只返回“有记录的日期”，故用循环按连续日期零填充，
     * 保证前端拿到无缺口的时间序列。
     */
    public function trend(int $days): array
    {
        $days = max(1, min(90, $days)); // 天数钳制，防止超大范围拖垮查询
        $since = now()->startOfDay()->subDays($days - 1); // 含今天在内往前推 $days 天的起点

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
            ->keyBy('date'); // 以日期字符串为键，便于逐日回填

        $buckets = [];

        // 逐日回填：DB 无记录的日期用 0 补齐，保证连续无缺口
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

    /**
     * 作用：返回最近一次巡检的摘要，供 Ops 首页卡片展示。
     *
     * @return array{latest:array|null,summary:array{pass:int,warn:int,fail:int}} 最新巡检摘要 + 状态计数
     *
     * 「为什么」：无任何巡检记录时 latest 为 null，summary 给出全 0 兜底，避免前端空指针。
     */
    public function summary(): array
    {
        $latest = OpsInspection::query()->latest('id')->first();

        return [
            'latest' => $latest ? $this->serializeSummary($latest) : null,
            'summary' => $latest ? $this->normalizeSummary($latest->summary ?? []) : ['pass' => 0, 'warn' => 0, 'fail' => 0],
        ];
    }

    /**
     * 作用：把一条巡检记录序列化为列表用摘要（不含逐项 checks）。
     *
     * @param  OpsInspection  $record  巡检记录
     * @return array 摘要字段（含状态计数、耗时、起止时间、脱敏后的失败信息）
     *
     * 「为什么」：failure_message 再次经 safeText 脱敏，防止历史里残留的敏感串在接口输出。
     */
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

    /**
     * 作用：在摘要基础上附带逐项 checks，用于巡检详情页。
     *
     * @param  OpsInspection  $record  巡检记录
     * @return array 摘要 + 脱敏后的 checks 明细
     *
     * 「为什么」：历史 checks 读出后再脱敏一遍，保证详情接口不泄敏（历史行可能是旧脱敏规则写入）。
     */
    public function serializeDetail(OpsInspection $record): array
    {
        return array_merge($this->serializeSummary($record), [
            'checks' => $this->sanitize($record->checks ?? []),
        ]);
    }

    /**
     * 作用：递归脱敏任意数组：敏感键整段屏蔽、字符串走文本脱敏、其余原样保留。
     *
     * @param  array  $payload  待脱敏的数组（可多层嵌套）
     * @return array 脱敏后的数组
     *
     * 「为什么」：巡检 checks 里可能夹带异常消息/配置值，敏感键直接 [FILTERED]，
     * 字符串值再经 safeText 兜底匹配 key=value 形式的泄露。
     */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            // 敏感键（如 password/token）整段屏蔽，不看值
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';

                continue;
            }

            // 数组递归下钻
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            // 字符串值走文本脱敏 + 截断
            if (is_string($value)) {
                $sanitized[$key] = $this->safeText($value);

                continue;
            }

            $sanitized[$key] = $value; // 数字/布尔等原样保留
        }

        return $sanitized;
    }

    /**
     * 作用：运行发布自检并把其明细规整为巡检统一的 check 结构。
     *
     * @return array<int, array{group:string,name:string,status:string,message:string,hint:string}> 规整后的 check 列表
     *
     * 「为什么」：发布自检自身抛错时不冒泡，降级为单条 fail check，保证整次巡检继续；
     * 子检查原 group 拼进 name，避免所有项都归到“发布自检”一个组下丢失层次。
     */
    private function runReleaseCheck(): array
    {
        try {
            $result = $this->releaseCheck->run();

            return collect($result['checks'] ?? [])
                ->map(fn (array $check): array => [
                    'group' => '发布自检',
                    // 把子检查原 group 拼进 name，保留原有分组语义
                    'name' => (string) ($check['group'] ?? '').' / '.(string) ($check['name'] ?? 'Check'),
                    'status' => $this->normalizeStatus((string) ($check['status'] ?? 'fail')),
                    'message' => (string) ($check['message'] ?? ''),
                    'hint' => (string) ($check['hint'] ?? ''),
                ])
                ->all();
        } catch (Throwable $e) {
            return [$this->failedCheck('发布自检', 'Release Check', $e)]; // 异常降级为一条 fail
        }
    }

    /**
     * 作用：以 inspection 为触发源评估告警规则，把命中/自动恢复情况转成一条 check。
     *
     * @return array<int, array> 单元素数组，含一条“告警评估”check
     *
     * 「为什么」：命中告警只算 warn（有告警待处理，不代表巡检本身失败）；
     * 评估抛错走 alertEvaluationFailureCheck 做“瞬时抖动 vs 连续失败”的降级判定。
     */
    private function runAlertEvaluation(): array
    {
        try {
            $result = $this->alerts->evaluate('inspection');
            $detected = (int) ($result['detected'] ?? 0);

            return [[
                'group' => '告警中心',
                'name' => 'Alert Evaluation',
                // 命中告警计 warn，无命中计 pass
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
     *
     * @param  Throwable  $e  告警评估抛出的异常
     * @return array<int, array> 单元素数组，含一条“告警评估”check（warn 或 fail）
     */
    private function alertEvaluationFailureCheck(Throwable $e): array
    {
        // 连续失败阈值：可配置，至少为 1；达到该值才升级为 fail
        $threshold = max(1, (int) config('ops.inspections.alert_eval_fail_threshold', 3));

        // 取最近 $threshold 条评估状态，倒序（最新在前）
        $recent = OpsAlertEvaluation::query()
            ->orderByDesc('id')
            ->limit($threshold)
            ->pluck('status');

        $failStreak = 0;

        // 从最新往回数连续 failure 的条数，遇到非 failure 立即停止
        foreach ($recent as $status) {
            if ($status === 'failure') {
                $failStreak++;

                continue;
            }

            break;
        }

        $persistent = $failStreak >= $threshold; // 连续失败达阈值 → 视为持续故障
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

    /**
     * 作用：以 dryRun 方式扫描日志错误，把扫描结果转成一条 check。
     *
     * @return array<int, array> 单元素数组，含一条“日志错误监控”check
     *
     * 「为什么」：巡检里用 dryRun，只探测不真正落库/升告警，避免巡检本身产生副作用；
     * 未启用或发现新错误都记 warn（提示但不判定巡检失败）。
     */
    private function runLogWatcher(): array
    {
        try {
            $result = $this->logWatcher->scan(dryRun: true); // 干跑：只统计不产生副作用
            $enabled = (bool) ($result['enabled'] ?? true);
            $detected = (int) ($result['detected'] ?? 0);

            return [[
                'group' => '日志中心',
                'name' => 'Log Error Watcher',
                // 未启用或发现新错误 → warn；否则 pass
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

    /**
     * 作用：读取队列监控摘要，输出“worker 运行”和“失败任务”两条 check。
     *
     * @return array<int, array> 两条 check（Queue Workers、Failed Jobs）
     *
     * 「为什么」：worker 未运行/有失败任务只记 warn（可运维处理，非致命）；
     * pending 通过跨队列累加 pending 字段汇总，用 data_get 容忍摘要结构缺字段。
     */
    private function runQueueHealth(): array
    {
        try {
            $summary = $this->queues->summary();
            $failedJobs = (int) data_get($summary, 'failed_jobs.count', 0);
            $workerRunning = (bool) data_get($summary, 'workers.running', false);
            // 累加所有队列的待处理任务数
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

    /**
     * 作用：把子检查抛出的异常统一包装成一条 fail check。
     *
     * @param  string  $group  分组名
     * @param  string  $name  检查名
     * @param  Throwable  $e  捕获的异常
     * @return array 一条 fail 结构（消息经脱敏）
     */
    private function failedCheck(string $group, string $name, Throwable $e): array
    {
        return [
            'group' => $group,
            'name' => $name,
            'status' => 'fail',
            'message' => $this->safeText($e->getMessage()), // 异常消息脱敏后展示
            'hint' => '检查相关服务配置和运行状态后重新执行巡检。',
        ];
    }

    /**
     * 作用：统计 checks 中各状态的数量。
     *
     * @param  array  $checks  check 列表
     * @return array{pass:int,warn:int,fail:int} 状态计数
     */
    private function summarize(array $checks): array
    {
        return [
            'pass' => collect($checks)->where('status', 'pass')->count(),
            'warn' => collect($checks)->where('status', 'warn')->count(),
            'fail' => collect($checks)->where('status', 'fail')->count(),
        ];
    }

    /**
     * 作用：把历史里读出的 summary 规整为非负整数三元组（兜底缺字段/负值）。
     *
     * @param  array  $summary  原始 summary（可能缺字段）
     * @return array{pass:int,warn:int,fail:int} 规整后的计数
     */
    private function normalizeSummary(array $summary): array
    {
        return [
            'pass' => max(0, (int) ($summary['pass'] ?? 0)),
            'warn' => max(0, (int) ($summary['warn'] ?? 0)),
            'fail' => max(0, (int) ($summary['fail'] ?? 0)),
        ];
    }

    /**
     * 作用：按“最严重优先”把多条 check 聚合为整体状态。
     *
     * @param  array  $checks  check 列表
     * @return string fail > warn > pass 的整体结论
     *
     * 「为什么」：只要有一条 fail 即整体 fail，其次 warn，全 pass 才 pass。
     */
    private function aggregateStatus(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');

        // fail 优先级最高
        if ($statuses->contains('fail')) {
            return 'fail';
        }

        // 其次 warn
        if ($statuses->contains('warn')) {
            return 'warn';
        }

        return 'pass';
    }

    /**
     * 作用：取第一条 fail 的消息（脱敏），作为整体失败摘要。
     *
     * @param  array  $checks  check 列表
     * @return string|null 首条失败消息，无则 null
     */
    private function firstFailureMessage(array $checks): ?string
    {
        $message = collect($checks)->firstWhere('status', 'fail')['message'] ?? null;

        return is_string($message) ? $this->safeText($message) : null;
    }

    /**
     * 作用：把外部传入的状态字符串规整到合法枚举，非法值回落 fail。
     *
     * @param  string  $status  原始状态
     * @return string pass/warn/fail 之一
     *
     * 「为什么」：回落 fail 而非 pass，遵循“未知即最坏”的保守原则，避免漏报。
     */
    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['pass', 'warn', 'fail'], true) ? $status : 'fail';
    }

    /**
     * 作用：对单段文本做敏感串脱敏并截断到 500 宽度。
     *
     * @param  string  $text  原始文本
     * @return string 脱敏并截断后的文本
     *
     * 「为什么」：正则把 key=value 的值替换为 [FILTERED] 但保留 key，便于排障时知道“什么被过滤了”；
     * mb_strimwidth 按显示宽度截断，避免超长异常串撑爆存储/接口。
     */
    private function safeText(string $text): string
    {
        $filtered = $text;

        // 逐条敏感正则替换；preg_replace 返回 null（出错）时回落原值
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $filtered = preg_replace($pattern, '$1=[FILTERED]', $filtered) ?? $filtered;
        }

        return mb_strimwidth($filtered, 0, 500, '...'); // 按显示宽度截断，超长以 ... 结尾
    }

    /**
     * 作用：判断某个键名是否为敏感键（精确相等或包含敏感词）。
     *
     * @param  string  $key  键名
     * @return bool 是否敏感
     *
     * 「为什么」：用 str_contains 做子串匹配，可命中 db_password、access_token 等派生键名。
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key); // 统一小写再比对，忽略大小写差异

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
