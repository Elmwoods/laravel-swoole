<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\DockerContainerRequest;
use App\Http\Requests\Admin\Ops\DockerControlRequest;
use App\Services\Ops\Docker\DockerService;
use Illuminate\Http\JsonResponse;

/**
 * Docker 运维控制器
 *
 * 作用：为后台运维中心提供 Docker 容器的只读查询（列表/汇总/资源统计/日志/版本）
 * 与生命周期控制（启动/停止/重启）能力，所有实际交互统一委托给 DockerService。
 *
 * 为什么：Controller 只负责 HTTP 层的参数取值与响应封装，
 * 把与 docker 守护进程/CLI 的具体交互全部下沉到 Service，保持职责单一、便于测试与复用。
 * 注意读操作与控制操作使用了不同的 FormRequest（DockerContainerRequest / DockerControlRequest），
 * 控制类操作走更严格的校验/授权。
 */
class DockerController extends Controller
{
    /**
     * 作用：构造函数，注入 Docker 服务。
     *
     * @param  DockerService  $docker  Docker 交互服务（被提升为受保护属性）
     *
     * 为什么：使用构造函数属性提升，让全部 action 共享同一个 Service 实例。
     */
    public function __construct(
        protected DockerService $docker
    ) {}

    /**
     * 容器列表
     *
     * 作用：返回当前所有 Docker 容器的列表。
     *
     * @return JsonResponse 统一封装的容器列表
     */
    public function containers()
    {
        // 委托 Service 拉取容器清单
        return $this->success($this->docker->containers());
    }

    /**
     * 容器汇总
     *
     * 作用：返回容器数量、运行状态分布等汇总统计。
     *
     * @return JsonResponse 统一封装的汇总数据
     */
    public function summary()
    {
        // 委托 Service 计算容器整体汇总
        return $this->success($this->docker->summary());
    }

    /**
     * 容器资源统计
     *
     * 作用：返回指定容器的资源占用统计（CPU/内存等）。
     *
     * @param  DockerContainerRequest  $request  已校验的请求，携带目标 containerId
     * @return JsonResponse 统一封装的资源统计
     */
    public function stats(DockerContainerRequest $request)
    {
        // 从已校验请求中取出容器 ID，委托 Service 采集其资源占用
        return $this->success(
            $this->docker->stats($request->containerId())
        );
    }

    /**
     * 容器日志
     *
     * 作用：返回指定容器的日志输出。
     *
     * @param  DockerContainerRequest  $request  已校验的请求，携带目标 containerId
     * @return JsonResponse 统一封装的容器日志
     */
    public function logs(DockerContainerRequest $request)
    {
        // 取容器 ID，委托 Service 读取该容器日志
        return $this->success(
            $this->docker->logs($request->containerId())
        );
    }

    /**
     * 作用：重启指定容器。
     *
     * @param  DockerControlRequest  $request  控制类请求，含更严格的校验/授权，携带 containerId
     * @return JsonResponse 统一封装的重启结果
     *
     * 为什么：属于会改变容器状态的危险操作，因此使用 DockerControlRequest 而非只读用的 DockerContainerRequest。
     */
    public function restart(DockerControlRequest $request)
    {
        // 委托 Service 对目标容器执行重启
        return $this->success(
            $this->docker->restart($request->containerId())
        );
    }

    /**
     * 作用：停止指定容器。
     *
     * @param  DockerControlRequest  $request  控制类请求，携带 containerId
     * @return JsonResponse 统一封装的停止结果
     *
     * 为什么：改变容器状态的危险操作，走 DockerControlRequest 严格校验。
     */
    public function stop(DockerControlRequest $request)
    {
        // 委托 Service 停止目标容器
        return $this->success(
            $this->docker->stop($request->containerId())
        );
    }

    /**
     * 作用：启动指定容器。
     *
     * @param  DockerContainerRequest  $request  请求对象，携带 containerId
     * @return JsonResponse 统一封装的启动结果
     */
    public function start(DockerContainerRequest $request)
    {
        // 委托 Service 启动目标容器
        return $this->success(
            $this->docker->start($request->containerId())
        );
    }

    /**
     * 作用：返回 Docker 引擎的版本信息。
     *
     * @return JsonResponse 统一封装的 Docker 版本信息
     */
    public function version()
    {
        // 委托 Service 查询 docker 版本
        return $this->success($this->docker->version());
    }
}
