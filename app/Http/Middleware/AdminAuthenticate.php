<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthenticate
{
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

        return $next($request);
    }
}
