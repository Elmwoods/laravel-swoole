<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminIpAccessService;
use App\Services\Admin\AdminSessionRegistryService;
use App\Services\Admin\AdminSessionSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthenticate
{
    public function __construct(
        private readonly AdminSessionSecurityService $sessions,
        private readonly AdminSessionRegistryService $sessionRegistry,
        private readonly AdminIpAccessService $ipAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin || ! $admin->is_active) {
            auth('admin')->logout();

            return response()->json([
                'code' => 401,
                'message' => '请先登录后台。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 401);
        }

        // IP 准入：被拉黑 / 移出白名单的活跃会话下次请求即被踢下线。
        if (! $this->ipAccess->allowedFor((string) $request->ip())) {
            auth('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'code' => 401,
                'message' => '您的 IP 不在允许访问后台的范围。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 401);
        }

        $sessionVersion = $request->session()->get('admin_session_version');

        if ($sessionVersion === null) {
            $request->session()->put('admin_session_version', (int) $admin->session_version);
            $this->sessionRegistry->ensureActive($admin, $request);
            $this->sessions->touch($request);

            return $next($request);
        }

        if (! $admin->sessionVersionMatches((int) $sessionVersion)) {
            auth('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'code' => 401,
                'message' => '登录状态已失效，请重新登录后台。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 401);
        }

        if (! $this->sessionRegistry->ensureActive($admin, $request)) {
            auth('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'code' => 401,
                'message' => '会话已被注销，请重新登录后台。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 401);
        }

        if ($this->sessions->isIdleTimedOut($this->sessions->lastActivityAt($request))) {
            auth('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'code' => 401,
                'message' => '登录已超时，请重新登录后台。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 401);
        }

        $this->sessions->touch($request);

        return $next($request);
    }
}
