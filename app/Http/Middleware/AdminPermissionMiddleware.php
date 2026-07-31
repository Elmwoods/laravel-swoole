<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台细粒度权限校验中间件。
 *
 * 在通过登录认证（AdminAuthenticate）之后进一步做"操作级"鉴权：
 * 通过路由中间件参数传入所需权限标识，校验当前管理员是否具备该权限，
 * 不具备则返回 403，具备则放行。用于把不同后台动作按权限点隔离。
 */
class AdminPermissionMiddleware
{
    /**
     * 校验当前管理员是否拥有指定权限。
     *
     * @param  string  $permission  路由声明的所需权限标识
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->user('admin');

        // 管理员缺失或不具备该权限点即拒绝（403 而非 401，表示已登录但无权）
        if (! $admin || ! $admin->hasPermission($permission)) {
            return response()->json([
                'code' => 403,
                'message' => '没有权限执行该操作。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 403);
        }

        return $next($request);
    }
}
