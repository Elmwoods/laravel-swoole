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

/**
 * 日志查询控制器
 *
 * 作用：为运维中心的「日志中心」提供多来源日志的在线查询与文件下载能力，
 * 覆盖 Laravel 应用日志、Octane/Swoole 日志、Redis 慢日志、Docker 容器日志与系统日志。
 * 每种来源都成对提供 在线查看（返回 JSON）与 下载（返回流式响应）两个 action。
 *
 * 为什么：不同来源的日志采集逻辑差异很大（文件 tail、Redis SLOWLOG、docker logs、journald 等），
 * 因此按来源拆分为独立的 *LogService，Controller 仅做请求→对应 Service 的分发与响应封装；
 * 各 action 通过方法参数注入所需的 Service，按需解析、互不牵连。
 * 查询条件统一由 LogQueryRequest 校验并转换为 LogQueryDTO 承载。
 */
class LogController extends Controller
{
    /**
     * Laravel 实时日志。
     *
     * 作用：按查询条件返回最新的 Laravel 应用日志（在线查看）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  LaravelLogService  $service  Laravel 日志采集服务
     * @return JsonResponse 统一封装的日志条目
     */
    public function laravel(
        LogQueryRequest $request,
        LaravelLogService $service
    ): JsonResponse {
        // 请求转 DTO 后委托 Service 拉取最新若干行日志
        return $this->success(
            $service->latest($request->dto())
        );
    }

    /**
     * 作用：下载 Laravel 日志为文件（流式响应）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  LaravelLogService  $service  Laravel 日志采集服务
     * @param  LogDownloadService  $download  日志下载/流式导出服务
     * @return StreamedResponse 以文件形式流式返回的日志
     *
     * 为什么：下载走 downloadDto() 而非普通 dto()，以便在 mode=full 时开启全量导出模式。
     */
    public function downloadLaravel(
        LogQueryRequest $request,
        LaravelLogService $service,
        LogDownloadService $download,
    ): StreamedResponse {
        // 采集日志后交给下载服务，以 'laravel' 作为导出文件名前缀
        return $download->download($service->latest($this->downloadDto($request)), 'laravel');
    }

    /**
     * Octane / Swoole 日志。
     *
     * 作用：按查询条件返回最新的 Octane/Swoole 运行日志（在线查看）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  OctaneLogService  $service  Octane 日志采集服务
     * @return JsonResponse 统一封装的日志条目
     */
    public function octane(
        LogQueryRequest $request,
        OctaneLogService $service
    ): JsonResponse {
        // 委托 Octane 日志服务拉取最新日志
        return $this->success(
            $service->latest($request->dto())
        );
    }

    /**
     * 作用：下载 Octane/Swoole 日志为文件（流式响应）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  OctaneLogService  $service  Octane 日志采集服务
     * @param  LogDownloadService  $download  日志下载/流式导出服务
     * @return StreamedResponse 以文件形式流式返回的日志
     */
    public function downloadOctane(
        LogQueryRequest $request,
        OctaneLogService $service,
        LogDownloadService $download,
    ): StreamedResponse {
        // 采集后以 'octane' 前缀导出
        return $download->download($service->latest($this->downloadDto($request)), 'octane');
    }

    /**
     * Redis 慢日志。
     *
     * 作用：返回 Redis 的慢查询日志（SLOWLOG）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  RedisLogService  $service  Redis 慢日志服务
     * @return JsonResponse 统一封装的慢日志条目
     *
     * 为什么：Redis 没有文件日志可 tail，慢日志来自 SLOWLOG 命令，故调用 slowLogs() 而非 latest()。
     */
    public function redis(
        LogQueryRequest $request,
        RedisLogService $service
    ): JsonResponse {
        // 委托 Service 读取 Redis SLOWLOG
        return $this->success(
            $service->slowLogs($request->dto())
        );
    }

    /**
     * 作用：下载 Redis 慢日志为文件（流式响应）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  RedisLogService  $service  Redis 慢日志服务
     * @param  LogDownloadService  $download  日志下载/流式导出服务
     * @return StreamedResponse 以文件形式流式返回的慢日志
     */
    public function downloadRedis(
        LogQueryRequest $request,
        RedisLogService $service,
        LogDownloadService $download,
    ): StreamedResponse {
        // 读取慢日志后以 'redis' 前缀导出
        return $download->download($service->slowLogs($this->downloadDto($request)), 'redis');
    }

    /**
     * Docker 容器日志。
     *
     * 作用：返回指定 Docker 容器的最新日志（在线查看）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  DockerLogService  $service  Docker 日志采集服务
     * @return JsonResponse 统一封装的容器日志
     *
     * 为什么：Docker 日志必须指定容器，故先取出 DTO 中的 container 字段并单独作为命名参数传入；
     * 当 container 缺省时用空串兜底，交由 Service 决定其默认行为。
     */
    public function docker(
        LogQueryRequest $request,
        DockerLogService $service
    ): JsonResponse {
        $dto = $request->dto();

        // container 单独作为命名参数传入，其余查询条件仍由 DTO 承载
        return $this->success(
            $service->latest(
                container: $dto->container ?? '',
                query: $dto,
            )
        );
    }

    /**
     * 作用：下载指定 Docker 容器日志为文件（流式响应）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  DockerLogService  $service  Docker 日志采集服务
     * @param  LogDownloadService  $download  日志下载/流式导出服务
     * @return StreamedResponse 以文件形式流式返回的容器日志
     */
    public function downloadDocker(
        LogQueryRequest $request,
        DockerLogService $service,
        LogDownloadService $download,
    ): StreamedResponse {
        // 使用导出专用 DTO（含 forExport 标记），同样将 container 拆为命名参数
        $dto = $this->downloadDto($request);

        return $download->download(
            $service->latest(container: $dto->container ?? '', query: $dto),
            'docker',
        );
    }

    /**
     * 系统日志。
     *
     * 作用：返回主机系统日志的最新内容（在线查看）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  SystemLogService  $service  系统日志采集服务
     * @return JsonResponse 统一封装的系统日志
     */
    public function system(
        LogQueryRequest $request,
        SystemLogService $service
    ): JsonResponse {
        // 委托系统日志服务拉取最新日志
        return $this->success(
            $service->latest($request->dto())
        );
    }

    /**
     * 作用：下载系统日志为文件（流式响应）。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @param  SystemLogService  $service  系统日志采集服务
     * @param  LogDownloadService  $download  日志下载/流式导出服务
     * @return StreamedResponse 以文件形式流式返回的系统日志
     */
    public function downloadSystem(
        LogQueryRequest $request,
        SystemLogService $service,
        LogDownloadService $download,
    ): StreamedResponse {
        // 采集后以 'system' 前缀导出
        return $download->download($service->latest($this->downloadDto($request)), 'system');
    }

    /**
     * 系统日志来源列表。
     *
     * 作用：返回系统日志的可选来源清单，供前端下拉筛选使用。
     *
     * @param  SystemLogService  $service  系统日志采集服务
     * @return JsonResponse 统一封装的来源列表（sources 键）
     */
    public function systemSources(SystemLogService $service): JsonResponse
    {
        // 委托 Service 枚举可用的日志来源
        return $this->success([
            'sources' => $service->sources(),
        ]);
    }

    /**
     * 作用：把查询请求转换为「下载专用」的 LogQueryDTO。
     *
     * @param  LogQueryRequest  $request  已校验的日志查询请求
     * @return LogQueryDTO 设置了 forExport 标记的查询 DTO
     *
     * 为什么：下载与在线查看共用同一份查询条件，唯一差别是当 mode 为 'full' 时
     * 需要开启 forExport（全量导出，而非仅返回尾部若干行），此处集中处理该差异避免各 download action 重复。
     */
    private function downloadDto(LogQueryRequest $request): LogQueryDTO
    {
        $dto = $request->dto();
        // mode=full 时标记为全量导出，Service 据此决定是否放开行数上限
        $dto->forExport = $dto->mode === 'full';

        return $dto;
    }
}
