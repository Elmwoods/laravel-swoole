<?php

namespace App\Services\Admin;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminSessionRegistryService
{
    public const TOKEN_KEY = 'admin_session_token';

    public function __construct(private readonly AdminLoginEventService $loginEvents) {}

    /**
     * 登录时注册一个新会话：生成随机 token 放进会话袋，注册表只存其 sha256。
     *
     * token 随会话数据走（不受 session id 重生成影响），是可靠且不可被客户端伪造的会话标识。
     */
    public function register(AdminUser $admin, Request $request): AdminSession
    {
        $token = Str::random(40);
        $request->session()->put(self::TOKEN_KEY, $token);

        return $this->persist($admin, $request, $token);
    }

    /**
     * 中间件强制点：当前会话被撤销 → false（调用方登出）；
     * 会话袋无 token（部署前旧会话）→ 懒注册；正常 → 刷新活跃时间。
     */
    public function ensureActive(AdminUser $admin, Request $request): bool
    {
        $token = $request->session()->get(self::TOKEN_KEY);

        if (! is_string($token) || $token === '') {
            $this->register($admin, $request);

            return true;
        }

        $session = AdminSession::query()
            ->where('session_token_hash', $this->hash($token))
            ->first();

        if ($session !== null && $session->revoked_at !== null) {
            return false;
        }

        $this->persist($admin, $request, $token);

        return true;
    }

    /**
     * 本人活跃会话列表（不含 hash），标记当前会话。
     */
    public function list(AdminUser $admin, Request $request): array
    {
        $currentHash = $this->currentHash($request);

        return AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AdminSession $session): array => [
                'id' => $session->id,
                'label' => $session->label,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'current' => $currentHash !== null && hash_equals($currentHash, (string) $session->session_token_hash),
                'last_activity_at' => optional($session->last_activity_at)->toDateTimeString(),
                'created_at' => optional($session->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 撤销本人一台会话（软标记），返回是否命中。
     */
    public function revoke(AdminUser $admin, int $sessionId): bool
    {
        return AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($sessionId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]) > 0;
    }

    /**
     * 撤销本人除当前会话外的所有活跃会话，返回撤销数量。
     */
    public function revokeOthers(AdminUser $admin, Request $request): int
    {
        $query = AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->whereNull('revoked_at');

        $currentHash = $this->currentHash($request);

        if ($currentHash !== null) {
            $query->where('session_token_hash', '!=', $currentHash);
        }

        return $query->update(['revoked_at' => now()]);
    }

    /**
     * 登出时清理当前会话行。
     */
    public function forget(Request $request): void
    {
        $currentHash = $this->currentHash($request);

        if ($currentHash === null) {
            return;
        }

        AdminSession::query()->where('session_token_hash', $currentHash)->delete();
    }

    private function persist(AdminUser $admin, Request $request, string $token): AdminSession
    {
        return AdminSession::query()->updateOrCreate(
            ['session_token_hash' => $this->hash($token)],
            [
                'admin_user_id' => $admin->id,
                'label' => $this->deviceLabel($request->userAgent()),
                'ip_address' => $request->ip(),
                'user_agent' => $this->loginEvents->userAgentSummary($request->userAgent()),
                'last_activity_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    private function currentHash(Request $request): ?string
    {
        $token = $request->session()->get(self::TOKEN_KEY);

        return is_string($token) && $token !== '' ? $this->hash($token) : null;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function deviceLabel(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $platform = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return "{$browser} · {$platform}";
    }
}
