<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminIpAccessService;
use App\Services\Admin\AdminSessionRegistryService;
use App\Services\Admin\AdminSessionSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台会话认证与安全闸门中间件。
 *
 * 在业务处理器之前，对每个后台请求依次校验多重会话安全条件，
 * 任何一项不满足都会立即注销登录并返回 401（且销毁会话、重置 CSRF token），
 * 使被踢下线 / 失效的会话在"下一次请求"即被拦截。校验维度包括：
 *  - 管理员存在且处于启用状态；
 *  - 来源 IP 处于允许访问后台的范围（黑白名单准入）；
 *  - 会话版本号与管理员当前 session_version 一致（用于全局强制下线）；
 *  - 会话在会话注册表中仍为活跃（用于单点/远程踢人）；
 *  - 未达到空闲超时时限。
 * 全部通过后刷新最近活动时间并放行。
 */
class AdminAuthenticate
{
    public function __construct(
        private readonly AdminSessionSecurityService $sessions,
        private readonly AdminSessionRegistryService $sessionRegistry,
        private readonly AdminIpAccessService $ipAccess,
    ) {}

    /**
     * 依次执行各项会话安全校验，任一失败即注销并返回 401，否则放行。
     *
     * 各早退分支：
     *  - 未登录或账号被停用 → 直接 401（此处只 logout，尚无有效会话可销毁）；
     *  - IP 不在准入范围 → 注销 + 销毁会话，防止已登录会话绕过 IP 管控；
     *  - 会话版本首次写入 → 初始化 admin_session_version 并放行（老会话平滑接管）；
     *  - 会话版本不匹配 → 说明管理员触发了全局下线，401；
     *  - 会话不在活跃注册表 → 说明被远程注销，401；
     *  - 空闲超时 → 401。
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        // 分支一：未认证或账号被停用，直接拒绝
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

        // 分支三：会话中尚未写入版本号（登录后首个受保护请求），初始化并放行
        if ($sessionVersion === null) {
            $request->session()->put('admin_session_version', (int) $admin->session_version);
            $this->sessionRegistry->ensureActive($admin, $request);
            $this->sessions->touch($request);

            return $next($request);
        }

        // 分支四：会话版本与管理员当前版本不符，说明已被全局强制下线
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

        // 分支五：会话已从活跃注册表中移除（被远程注销 / 踢下线）
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

        // 分支六：距离上次活动已超过空闲超时阈值，强制重新登录
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

        // 全部校验通过：刷新最近活动时间戳（用于空闲超时计算）后放行
        $this->sessions->touch($request);

        return $next($request);
    }
}
