<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsReleaseCheck;
use Throwable;

/**
 * 发布自检历史服务：负责“执行一次发布自检并把结果落库/回放”。
 *
 * 作用：包装 OpsReleaseCheckService，把每次自检结果记为 OpsReleaseCheck 历史行
 * （含操作者、状态、计数、耗时、脱敏明细），并提供概览与历史记录的序列化。
 *
 * 「为什么」：自检本身可能抛异常，这里用 try/catch 把异常也落成一条 fail 历史（而非丢失记录），
 * 保证“每次点击都有痕迹”；落库前后都经敏感正则脱敏，防止历史泄露密钥。
 */
class OpsReleaseCheckHistoryService
{
    // 敏感文本正则：匹配 key=value / key:value / key value 形式，值替换为 [FILTERED]
    private const SENSITIVE_PATTERNS = [
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*=\s*[^,\s;]+/iu',
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*:\s*[^,\s;]+/iu',
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s+value\b/iu',
    ];

    /**
     * 作用：注入底层发布自检服务（只读构造器属性提升）。
     *
     * @param  OpsReleaseCheckService  $releaseCheck  实际执行八类检查的服务
     */
    public function __construct(
        private readonly OpsReleaseCheckService $releaseCheck,
    ) {}

    /**
     * 作用：返回发布自检页概览——所需权限、可用命令行、最近一次自检摘要。
     *
     * @return array{permission:string,commands:array<string,string>,latest:array|null} 概览数据
     *
     * 「为什么」：commands 给运维展示等价的 CLI 用法（default/json/strict），方便脱离 UI 在服务器执行。
     */
    public function overview(): array
    {
        $latest = OpsReleaseCheck::query()
            ->latest('id')
            ->first();

        return [
            'permission' => 'ops.release.view',
            'commands' => [
                'default' => './vendor/bin/sail artisan ops:release-check',
                'json' => './vendor/bin/sail artisan ops:release-check --json',
                'strict' => './vendor/bin/sail artisan ops:release-check --strict',
            ],
            'latest' => $latest ? $this->serializeSummary($latest) : null,
        ];
    }

    /**
     * 作用：执行一次发布自检并落库为一条历史记录，返回该记录。
     *
     * @param  AdminUser|null  $admin  触发人（CLI/无登录场景为 null）
     * @return OpsReleaseCheck 落库后的历史记录
     *
     * 「为什么」：自检抛异常也不冒泡——降级为 status=fail、单条错误 check 落库，
     * 保证“执行必留痕”；status 落库前再做一次合法枚举校验，防止底层返回异常值。
     */
    public function runAndRecord(?AdminUser $admin): OpsReleaseCheck
    {
        $startedAt = now();
        $started = microtime(true); // 高精度计时起点

        try {
            $result = $this->releaseCheck->run();
            $status = (string) ($result['status'] ?? 'fail'); // 缺 status 时保守当 fail
            $summary = $this->normalizeSummary($result['summary'] ?? []);
            $checks = $this->sanitizePayload((array) ($result['checks'] ?? [])); // 明细脱敏
            $errorMessage = null;
        } catch (Throwable $e) {
            // 自检自身异常：降级为一条 fail 历史，而非丢失记录
            $status = 'fail';
            $summary = ['pass' => 0, 'warn' => 0, 'fail' => 1];
            $errorMessage = $this->safeMessage($e->getMessage()); // 异常消息脱敏
            $checks = [[
                'group' => '发布自检',
                'name' => 'Release Check Execution',
                'status' => 'fail',
                'message' => $errorMessage,
                'hint' => '检查数据库、权限、配置和运行环境后重新执行发布自检。',
            ]];
        }

        $finishedAt = now();
        $durationMs = max(0, (int) round((microtime(true) - $started) * 1000)); // 耗时毫秒，钳到 >=0

        return OpsReleaseCheck::query()->create([
            'admin_user_id' => $admin?->id,
            'admin_email' => $admin?->email,
            // 落库前再校验一次状态枚举，非法值回落 fail（未知即最坏）
            'status' => in_array($status, ['pass', 'warn', 'fail'], true) ? $status : 'fail',
            'summary' => $summary,
            'checks' => $checks,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * 作用：把一条历史记录序列化为列表摘要（不含逐项 checks）。
     *
     * @param  OpsReleaseCheck  $record  历史记录
     * @return array 摘要字段（含状态计数、耗时、起止时间、脱敏后的错误信息）
     *
     * 「为什么」：error_message 再经 safeMessage 脱敏，防止历史里残留敏感串在接口输出。
     */
    public function serializeSummary(OpsReleaseCheck $record): array
    {
        return [
            'id' => $record->id,
            'admin_user_id' => $record->admin_user_id,
            'admin_email' => $record->admin_email,
            'status' => $record->status,
            'summary' => $this->normalizeSummary($record->summary ?? []),
            'duration_ms' => $record->duration_ms,
            'started_at' => optional($record->started_at)->toDateTimeString(),
            'finished_at' => optional($record->finished_at)->toDateTimeString(),
            'error_message' => $record->error_message ? $this->safeMessage($record->error_message) : null,
            'created_at' => optional($record->created_at)->toDateTimeString(),
        ];
    }

    /**
     * 作用：在摘要基础上附带脱敏后的逐项 checks，用于历史详情页。
     *
     * @param  OpsReleaseCheck  $record  历史记录
     * @return array 摘要 + checks 明细
     */
    public function serializeDetail(OpsReleaseCheck $record): array
    {
        return array_merge($this->serializeSummary($record), [
            'checks' => $this->sanitizePayload($record->checks ?? []), // 读出后再脱敏一次
        ]);
    }

    /**
     * 作用：把 summary 规整为非负整数三元组（兜底缺字段/负值）。
     *
     * @param  array  $summary  原始 summary
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
     * 作用：递归脱敏明细数组：敏感键整段屏蔽、字符串走文本脱敏、其余原样保留。
     *
     * @param  array  $payload  待脱敏数组（可嵌套）
     * @return array 脱敏后的数组
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            // 敏感键整段屏蔽
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';

                continue;
            }

            // 数组递归下钻
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);

                continue;
            }

            // 字符串走文本脱敏 + 截断
            if (is_string($value)) {
                $sanitized[$key] = $this->safeMessage($value);

                continue;
            }

            $sanitized[$key] = $value; // 数字/布尔等原样保留
        }

        return $sanitized;
    }

    /**
     * 作用：对单段文本做敏感串脱敏并截断到 500 宽度。
     *
     * @param  string  $message  原始文本
     * @return string 脱敏并截断后的文本
     *
     * 「为什么」：正则保留 key、把 value 替换为 [FILTERED]；preg_replace 返回 null 时回落原值不丢内容。
     */
    private function safeMessage(string $message): string
    {
        $filtered = $message;

        // 逐条敏感正则替换
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $filtered = preg_replace($pattern, '$1=[FILTERED]', $filtered) ?? $filtered;
        }

        return mb_strimwidth($filtered, 0, 500, '...'); // 按显示宽度截断
    }

    /**
     * 作用：判断某键名是否敏感（精确相等或包含敏感词）。
     *
     * @param  string  $key  键名
     * @return bool 是否敏感
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key); // 统一小写比对

        foreach (['password', 'secret', 'token', 'cookie', 'private_key', 'authorization', 'api_key'] as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
