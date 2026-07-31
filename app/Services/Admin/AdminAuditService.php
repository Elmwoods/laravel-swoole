<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;

/**
 * 后台审计日志服务。
 *
 * 属于后台安全 / 审计子系统的写入端：负责把每一次后台敏感操作（登录、增删改、
 * 权限变更等）落库到 admin_audit_logs，供事后追责、异常扫描（AuditAnomalyScan）、
 * IP 自动封禁（AdminIpAutoBanService）等下游消费。
 *
 * 核心职责：
 * - record()：从 Request 提取操作者、IP、UA 等上下文并写一条审计记录。
 * - sanitizePayload()：写库前对请求体做脱敏 + 截断，避免把明文密码 / token 存进日志。
 */
class AdminAuditService
{
    /**
     * 脱敏关键字白名单：payload 中键名命中（等值或子串）即被替换成 [FILTERED]。
     * 「为什么」用子串匹配而非等值：像 new_password、user_api_key 这类派生字段也要一并脱敏。
     */
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

    /**
     * 作用：写一条后台审计日志。
     *
     * @param  Request  $request  当前请求，用于提取操作者、IP、User-Agent 等上下文。
     * @param  string  $module  业务模块标识（如 admin.auth、admin.user）。
     * @param  string  $action  动作标识（如 login、create、delete）。
     * @param  string  $result  结果（如 success / failure）。
     * @param  int|null  $statusCode  HTTP 状态码，可空。
     * @param  string|null  $targetType  操作对象类型（如 AdminUser），可空。
     * @param  string|null  $targetId  操作对象主键，可空。
     * @param  array|null  $payload  自定义载荷；为 null 时回退到请求体（剔除 _token）。
     * @param  string|null  $message  附加说明文本，可空。
     * @param  AdminUser|null  $admin  显式操作者；为空时从 request 的 admin guard 取。
     * @return AdminAuditLog 已落库的审计记录。
     */
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
        // 操作者优先用显式传入的 $admin；否则回退到已认证的 admin guard 用户。
        $actor = $admin ?: $request->user('admin');
        // 未显式给 payload 时，用整个请求体（剔除 CSRF 的 _token）作为审计载荷。
        $requestPayload = $payload ?? $request->except(['_token']);

        return AdminAuditLog::query()->create([
            'admin_user_id' => $actor?->id,
            // 未登录场景（如登录失败）operator 为空，此时用请求里的 email 兜底记录尝试者。
            'admin_email' => $actor?->email ?? $request->input('email'),
            'module' => $module,
            'action' => $action,
            'result' => $result,
            'status_code' => $statusCode,
            'target_type' => $targetType,
            'target_id' => $targetId,
            // 载荷入库前统一脱敏 + 截断，避免明文敏感信息写进审计表。
            'payload' => $this->sanitizePayload($requestPayload),
            'ip_address' => $request->ip(),
            // UA 截到 1000 字，防超长字段撑爆列 / 拖慢查询。
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'message' => $message,
        ]);
    }

    /**
     * 作用：对审计载荷做递归脱敏与截断，产出可安全落库的数组。
     *
     * 「为什么」递归：请求体常是嵌套结构，敏感键可能藏在子数组里，必须逐层下钻。
     *
     * @param  array  $payload  原始载荷（可嵌套）。
     * @return array 脱敏后的载荷；敏感键值替换为 [FILTERED]，长字符串截断到 500 宽。
     */
    public function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            // 敏感键：直接抹成占位符，绝不落明文。
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';

                continue;
            }

            // 子数组：递归下钻脱敏。
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);

                continue;
            }

            // 长字符串截断到 500 宽（超出补 ...），控制单条日志体积。
            if (is_string($value)) {
                $sanitized[$key] = mb_strimwidth($value, 0, 500, '...');

                continue;
            }

            // 其它标量（int/bool/null 等）原样保留。
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * 作用：判断某个键名是否属于敏感字段（需脱敏）。
     *
     * 「为什么」先转小写再比对：键名大小写不定（Password / TOKEN），归一化后统一匹配。
     *
     * @param  string  $key  待判定的键名。
     * @return bool 等值或子串命中敏感白名单时返回 true。
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            // 等值或子串命中都算敏感（子串可覆盖 new_password 等派生字段）。
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
