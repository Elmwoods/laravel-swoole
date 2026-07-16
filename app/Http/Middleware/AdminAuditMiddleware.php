<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AdminAuditMiddleware
{
    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $module, string $action)
    {
        $this->mergeScalarRouteParameters($request);

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
            $statusCode = $this->statusCodeForException($e);

            $this->audit->record(
                request: $request,
                module: $module,
                action: $action,
                result: 'failure',
                statusCode: $statusCode,
                message: $e->getMessage(),
            );

            throw $e;
        }
    }

    private function mergeScalarRouteParameters(Request $request): void
    {
        $route = $request->route();

        if ($route === null) {
            return;
        }

        $parameters = [];

        foreach ($route->parameters() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parameters[$key] = $value;
            }
        }

        if ($parameters !== []) {
            $request->merge($parameters);
        }
    }

    private function statusCodeForException(Throwable $e): int
    {
        if ($e instanceof ValidationException) {
            return 422;
        }

        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }
}
