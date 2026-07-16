<?php

namespace App\Http\Controllers\Admin\Ops\Log;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\LogQueryRequest;
use App\Services\Ops\Log\DockerLogService;
use App\Services\Ops\Log\LaravelLogService;
use App\Services\Ops\Log\OctaneLogService;
use App\Services\Ops\Log\RedisLogService;
use App\Services\Ops\Log\SystemLogService;
use Illuminate\Http\JsonResponse;

class LogController extends Controller
{
    /**
     * Laravel 实时日志。
     */
    public function laravel(
        LogQueryRequest   $request,
        LaravelLogService $service
    ): JsonResponse
    {
        return $this->success(
            $service->latest($request->dto())
        );
    }

    /**
     * Octane / Swoole 日志。
     */
    public function octane(
        LogQueryRequest  $request,
        OctaneLogService $service
    ): JsonResponse
    {
        return $this->success(
            $service->latest($request->dto())
        );
    }

    /**
     * Redis 慢日志。
     */
    public function redis(
        LogQueryRequest $request,
        RedisLogService $service
    ): JsonResponse
    {
        return $this->success(
            $service->slowLogs($request->dto())
        );
    }

    /**
     * Docker 容器日志。
     */
    public function docker(
        LogQueryRequest  $request,
        DockerLogService $service
    ): JsonResponse
    {
        $dto = $request->dto();

        return $this->success(
            $service->latest(
                container: $dto->container ?? '',
                query: $dto,
            )
        );
    }

    /**
     * 系统日志。
     */
    public function system(
        LogQueryRequest $request,
        SystemLogService $service
    ): JsonResponse
    {
        return $this->success(
            $service->latest($request->dto())
        );
    }

    /**
     * 系统日志来源列表。
     */
    public function systemSources(SystemLogService $service): JsonResponse
    {
        return $this->success([
            'sources' => $service->sources(),
        ]);
    }
}
