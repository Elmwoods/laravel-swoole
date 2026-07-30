<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;

class AdminAuditService
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'remember_token',
        'authorization',
        'cookie',
        'bot_token',
        'chat_id',
        'secret',
        'api_key',
    ];

    public function record(
        Request $request,
        string $module,
        string $action,
        string $result,
        ?int $statusCode = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $payload = null,
        ?string $message = null,
        ?AdminUser $admin = null,
    ): AdminAuditLog {
        $actor = $admin ?: $request->user('admin');
        $requestPayload = $payload ?? $request->except(['_token']);

        return AdminAuditLog::query()->create([
            'admin_user_id' => $actor?->id,
            'admin_email' => $actor?->email ?? $request->input('email'),
            'module' => $module,
            'action' => $action,
            'result' => $result,
            'status_code' => $statusCode,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $this->sanitizePayload($requestPayload),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'message' => $message,
        ]);
    }

    public function sanitizePayload(array $payload): array
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
                $sanitized[$key] = mb_strimwidth($value, 0, 500, '...');

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
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
