<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAuditService;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class AdminAuditMiddleware
{
    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $module, string $action)
    {
        try {
            $response = $next($request);
            $statusCode = $response->getStatusCode();
            $this->audit->record(
                request: $request,
                module: $module,
                action: $action,
                result: $statusCode >= 400 ? 'failure' : 'success',
                statusCode: $statusCode,
            );

            return $response;
        } catch (Throwable $e) {
            $this->audit->record(
                request: $request,
                module: $module,
                action: $action,
                result: 'failure',
                statusCode: 500,
                message: $e->getMessage(),
            );

            throw $e;
        }
    }
}
