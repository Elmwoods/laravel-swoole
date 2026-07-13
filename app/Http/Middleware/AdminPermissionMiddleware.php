<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->user('admin');

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
