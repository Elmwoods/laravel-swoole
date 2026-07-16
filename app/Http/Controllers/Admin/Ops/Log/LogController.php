<?php

namespace App\Http\Controllers\Admin\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\LogQueryRequest;
use App\Services\Ops\Log\DockerLogService;
use App\Services\Ops\Log\LaravelLogService;
use App\Services\Ops\Log\LogDownloadService;
use App\Services\Ops\Log\OctaneLogService;
use App\Services\Ops\Log\RedisLogService;
use App\Services\Ops\Log\SystemLogService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function downloadLaravel(
        LogQueryRequest $request,
        LaravelLogService $service,
        LogDownloadService $download,
    ): StreamedResponse
    {
        return $download->download($service->latest($this->downloadDto($request)), 'laravel');
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

    public function downloadOctane(
        LogQueryRequest $request,
        OctaneLogService $service,
        LogDownloadService $download,
    ): StreamedResponse
    {
        return $download->download($service->latest($this->downloadDto($request)), 'octane');
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

    public function downloadRedis(
        LogQueryRequest $request,
        RedisLogService $service,
        LogDownloadService $download,
    ): StreamedResponse
    {
        return $download->download($service->slowLogs($this->downloadDto($request)), 'redis');
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

    public function downloadDocker(
        LogQueryRequest $request,
        DockerLogService $service,
        LogDownloadService $download,
    ): StreamedResponse
    {
        $dto = $this->downloadDto($request);

        return $download->download(
            $service->latest(container: $dto->container ?? '', query: $dto),
            'docker',
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

    public function downloadSystem(
        LogQueryRequest $request,
        SystemLogService $service,
        LogDownloadService $download,
    ): StreamedResponse
    {
        return $download->download($service->latest($this->downloadDto($request)), 'system');
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

    private function downloadDto(LogQueryRequest $request): LogQueryDTO
    {
        $dto = $request->dto();
        $dto->forExport = $dto->mode === 'full';

        return $dto;
    }
}
