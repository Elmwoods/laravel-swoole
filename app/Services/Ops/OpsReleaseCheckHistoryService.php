<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsReleaseCheck;
use Throwable;

class OpsReleaseCheckHistoryService
{
    private const SENSITIVE_PATTERNS = [
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*=\s*[^,\s;]+/iu',
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s*:\s*[^,\s;]+/iu',
        '/\b(password|token|secret|cookie|authorization|private_key|api_key)\s+value\b/iu',
    ];

    public function __construct(
        private readonly OpsReleaseCheckService $releaseCheck,
    ) {}

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

    public function runAndRecord(?AdminUser $admin): OpsReleaseCheck
    {
        $startedAt = now();
        $started = microtime(true);

        try {
            $result = $this->releaseCheck->run();
            $status = (string) ($result['status'] ?? 'fail');
            $summary = $this->normalizeSummary($result['summary'] ?? []);
            $checks = $this->sanitizePayload((array) ($result['checks'] ?? []));
            $errorMessage = null;
        } catch (Throwable $e) {
            $status = 'fail';
            $summary = ['pass' => 0, 'warn' => 0, 'fail' => 1];
            $errorMessage = $this->safeMessage($e->getMessage());
            $checks = [[
                'group' => '发布自检',
                'name' => 'Release Check Execution',
                'status' => 'fail',
                'message' => $errorMessage,
                'hint' => '检查数据库、权限、配置和运行环境后重新执行发布自检。',
            ]];
        }

        $finishedAt = now();
        $durationMs = max(0, (int) round((microtime(true) - $started) * 1000));

        return OpsReleaseCheck::query()->create([
            'admin_user_id' => $admin?->id,
            'admin_email' => $admin?->email,
            'status' => in_array($status, ['pass', 'warn', 'fail'], true) ? $status : 'fail',
            'summary' => $summary,
            'checks' => $checks,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'error_message' => $errorMessage,
        ]);
    }

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

    public function serializeDetail(OpsReleaseCheck $record): array
    {
        return array_merge($this->serializeSummary($record), [
            'checks' => $this->sanitizePayload($record->checks ?? []),
        ]);
    }

    private function normalizeSummary(array $summary): array
    {
        return [
            'pass' => max(0, (int) ($summary['pass'] ?? 0)),
            'warn' => max(0, (int) ($summary['warn'] ?? 0)),
            'fail' => max(0, (int) ($summary['fail'] ?? 0)),
        ];
    }

    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->safeMessage($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function safeMessage(string $message): string
    {
        $filtered = $message;

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $filtered = preg_replace($pattern, '$1=[FILTERED]', $filtered) ?? $filtered;
        }

        return mb_strimwidth($filtered, 0, 500, '...');
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (['password', 'secret', 'token', 'cookie', 'private_key', 'authorization', 'api_key'] as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
