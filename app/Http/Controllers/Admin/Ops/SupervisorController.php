<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\SupervisorControlRequest;
use App\Http\Requests\Admin\Ops\SupervisorProcessRequest;
use App\Services\Ops\SupervisorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Supervisor 进程管理控制器
 * -------------------------------------------------
 * 作用：运维后台对 Supervisor 托管进程的 HTTP 控制入口，提供进程
 *       状态查询、启动/停止/重启、配置重读/热更新、日志读取等能力。
 * 说明：所有对 supervisorctl 的实际调用都封装在 SupervisorService 中，
 *       控制器只负责「解析进程名 -> 委派服务 -> 统一响应」。
 * 注意：start/stop/restart 属于会改变进程运行状态的敏感操作，进程名
 *       由对应的表单请求（Request）负责校验白名单，避免任意进程被操控。
 * -------------------------------------------------
 */
class SupervisorController extends Controller
{
    // 引入统一的 API 响应封装（success 等辅助方法）
    use ApiResponse;

    /**
     * 作用：查询所有 Supervisor 托管进程的当前状态。
     *
     * @param  SupervisorService  $service  Supervisor 控制服务（容器自动注入）
     * @return JsonResponse 统一成功响应，data 为各进程的运行状态列表
     */
    public function status(
        SupervisorService $service
    ): JsonResponse {
        // 委派服务层执行 `supervisorctl status` 并返回解析后的进程状态
        return $this->success(
            $service->status()
        );
    }

    /**
     * 作用：启动指定的 Supervisor 进程。
     *
     * @param  SupervisorProcessRequest  $request  携带并校验目标进程名的请求
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data 为启动结果
     *
     * 为什么：进程名来自 $request->serviceName()，由 Request 层做白名单校验，
     *         防止调用方通过任意名称启动未授权进程。
     */
    public function start(
        SupervisorProcessRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        // 取出已校验的进程名并委派服务层执行启动
        return $this->success(
            $service->start($request->serviceName())
        );
    }

    /**
     * 作用：停止指定的 Supervisor 进程。
     *
     * @param  SupervisorControlRequest  $request  携带并校验目标进程名的控制请求
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data 为停止结果
     *
     * 为什么：停止是高风险操作，改用更严格的 SupervisorControlRequest 做进程名校验。
     */
    public function stop(
        SupervisorControlRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        // 取出已校验的进程名并委派服务层执行停止
        return $this->success(
            $service->stop($request->serviceName())
        );
    }

    /**
     * 作用：重启指定的 Supervisor 进程。
     *
     * @param  SupervisorControlRequest  $request  携带并校验目标进程名的控制请求
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data 为重启结果
     *
     * 为什么：重启同为高风险的状态变更操作，与 stop 一样使用 SupervisorControlRequest 校验。
     */
    public function restart(
        SupervisorControlRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        // 取出已校验的进程名并委派服务层执行重启
        return $this->success(
            $service->restart($request->serviceName())
        );
    }

    /**
     * 作用：让 Supervisor 重新读取配置文件（reread），不重启进程。
     *
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data.result 为 reread 命令输出
     *
     * 为什么：reread 只加载最新配置到内存，用于在 update 前预览配置变更。
     */
    public function reread(
        SupervisorService $service
    ): JsonResponse {
        return $this->success([
            'result' => $service->reread(),
        ]);
    }

    /**
     * 作用：应用配置变更（update），使新增/修改的进程配置生效。
     *
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data.result 为 update 命令输出
     *
     * 为什么：update 会根据最新配置增删或重启相关进程，是 reread 之后的实际生效步骤。
     */
    public function update(
        SupervisorService $service
    ): JsonResponse {
        return $this->success([
            'result' => $service->update(),
        ]);
    }

    /**
     * 作用：实时跟踪（tail）指定进程的最新日志片段。
     *
     * @param  SupervisorProcessRequest  $request  携带并校验目标进程名的请求
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data.logs 为日志尾部内容
     */
    public function tail(
        SupervisorProcessRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        return $this->success([
            'logs' => $service->tail($request->serviceName()),
        ]);
    }

    /**
     * 作用：读取指定进程的日志内容。
     *
     * @param  SupervisorProcessRequest  $request  携带并校验目标进程名的请求
     * @param  SupervisorService  $service  Supervisor 控制服务
     * @return JsonResponse 统一成功响应，data.service 为进程名，data.logs 为日志内容
     *
     * 为什么：这里先把进程名取到局部变量 $name，既作为响应回显、又复用于日志读取，避免重复解析。
     */
    public function logs(
        SupervisorProcessRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        // 取出已校验的进程名，复用于响应回显与日志查询
        $name = $request->serviceName();

        return $this->success([
            'service' => $name,
            'logs' => $service->logs($name),
        ]);
    }
}
