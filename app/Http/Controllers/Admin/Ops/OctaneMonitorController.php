<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OctaneMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Octane 运行监控控制器
 *
 * 作用：为运维中心提供 Octane（Swoole）运行时的监控指标只读查询，
 * 例如 worker 数量、请求量、内存占用等运行态指标。
 *
 * 为什么：与负责生命周期控制的 OctaneController 区分——本控制器只做「观测」不做「控制」，
 * 依赖 OctaneMonitorService 采集运行指标，因此无需危险操作确认。
 */
class OctaneMonitorController extends Controller
{
    // ApiResponse：统一 JSON 响应封装
    use ApiResponse;

    /**
     * 作用：返回 Octane 运行时的监控状态指标。
     *
     * @param  OctaneMonitorService  $monitorService  Octane 运行监控服务
     * @return JsonResponse 统一封装的监控指标
     *
     * 为什么：Service 返回的是结构化状态对象（DTO/值对象），需 toArray() 展平为数组以便 JSON 序列化。
     */
    public function status(OctaneMonitorService $monitorService): JsonResponse
    {
        // 委托 Service 采集运行指标，并将状态对象转为数组返回
        return $this->success($monitorService->getStatus()->toArray());
    }
}
