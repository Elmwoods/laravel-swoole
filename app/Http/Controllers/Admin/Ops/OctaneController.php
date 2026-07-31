<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\OpsConfirmActionRequest;
use App\Services\Ops\OctaneControlService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Octane 进程控制器
 *
 * 作用：为运维中心提供 Laravel Octane（Swoole）服务的状态查询与生命周期控制
 * （热重载 reload / 重启 restart / 停止 stop），实际操作委托给 OctaneControlService。
 *
 * 为什么：reload/restart/stop 均为影响线上服务可用性的危险动作，
 * 因此三者统一通过 OpsConfirmActionRequest 强制二次确认后才放行，而只读的 status 不需确认。
 */
class OctaneController extends Controller
{
    // ApiResponse：统一 JSON 响应封装
    use ApiResponse;

    /**
     * 作用：返回 Octane 服务的当前运行状态。
     *
     * @param  OctaneControlService  $service  Octane 控制服务
     * @return JsonResponse 统一封装的 Octane 状态
     */
    public function status(OctaneControlService $service): JsonResponse
    {
        // 只读查询，无需确认，直接委托 Service
        return $this->success($service->status());
    }

    /**
     * 作用：热重载 Octane worker（不中断服务地重新加载代码）。
     *
     * @param  OpsConfirmActionRequest  $request  带二次确认校验的危险操作请求
     * @param  OctaneControlService  $service  Octane 控制服务
     * @return JsonResponse 统一封装的重载结果（reloaded 布尔）
     *
     * 为什么：注入 OpsConfirmActionRequest 即在进入方法前完成「确认」校验，未确认将被拦截。
     */
    public function reload(OpsConfirmActionRequest $request, OctaneControlService $service): JsonResponse
    {
        // 确认通过后委托 Service 执行热重载
        return $this->success([
            'reloaded' => $service->reload(),
        ]);
    }

    /**
     * 作用：重启 Octane 服务。
     *
     * @param  OpsConfirmActionRequest  $request  带二次确认校验的危险操作请求
     * @param  OctaneControlService  $service  Octane 控制服务
     * @return JsonResponse 统一封装的重启结果（restarted 布尔）
     */
    public function restart(OpsConfirmActionRequest $request, OctaneControlService $service): JsonResponse
    {
        // 确认通过后委托 Service 执行重启
        return $this->success([
            'restarted' => $service->restart(),
        ]);
    }

    /**
     * 作用：停止 Octane 服务。
     *
     * @param  OpsConfirmActionRequest  $request  带二次确认校验的危险操作请求
     * @param  OctaneControlService  $service  Octane 控制服务
     * @return JsonResponse 统一封装的停止结果（stopped 布尔）
     */
    public function stop(OpsConfirmActionRequest $request, OctaneControlService $service): JsonResponse
    {
        // 确认通过后委托 Service 执行停止
        return $this->success([
            'stopped' => $service->stop(),
        ]);
    }
}
