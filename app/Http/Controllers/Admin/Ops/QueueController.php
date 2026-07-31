<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\QueueMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Queue 监控控制器
 *
 * 作用：为运维中心提供消息队列（Queue）运行状态的 HTTP 接口，
 * 汇总各队列的积压深度、等待/延迟任务数、失败任务等指标。
 * 具体的队列指标采集与聚合由 QueueMonitorService 负责，控制器仅做响应包装。
 *
 * 为什么：队列深度等指标需要访问底层队列驱动（如 Redis），
 * 将这部分逻辑收敛到 Service，便于按驱动扩展与测试。
 */
class QueueController extends Controller
{
    // 引入统一 API 响应 Trait
    use ApiResponse;

    /**
     * 构造函数：注入队列监控服务。
     *
     * @param  QueueMonitorService  $service  队列监控服务，负责采集并聚合队列指标
     *
     * 为什么：通过构造函数注入，使控制器与队列指标采集实现解耦。
     */
    public function __construct(
        private readonly QueueMonitorService $service
    ) {}

    /**
     * Queue 总览
     *
     * 作用：返回各队列的总览指标（积压深度、待处理/失败任务数等聚合结果）。
     *
     * @return JsonResponse 队列总览数据的统一响应
     */
    public function summary(): JsonResponse
    {
        // 委托队列监控服务采集并聚合各队列深度/任务量后返回
        return $this->success($this->service->summary());
    }
}
