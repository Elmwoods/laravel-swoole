<?php

namespace App\Http\Controllers\Admin\Ops\System;

use App\Http\Controllers\Controller;
use App\Services\Ops\System\DiskService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 手动触发 WebSocket 推送
 * （后期可改 cron / supervisor）
 * -------------------------------------------------
 * 作用：运维后台的磁盘指标「主动推送」入口，被调用时立即采集一次
 *       磁盘指标并通过 WebSocket 广播给在线的前端客户端。
 * 说明：当前由人工/接口手动触发，未来可迁移到 cron 或 supervisor 定时任务，
 *       届时无需改动前端订阅逻辑，只需换触发源。
 * -------------------------------------------------
 */
class DiskPushController extends Controller
{
    // 引入统一的 API 响应封装（success 等辅助方法）
    use ApiResponse;

    /**
     * 作用：构造函数，注入磁盘服务（复用其采集与推送能力）。
     *
     * @param  DiskService  $service  磁盘指标采集/推送服务（容器自动注入）
     * @return void
     */
    public function __construct(
        protected DiskService $service
    ) {}

    /**
     * 作用：立即采集磁盘指标并经 WebSocket 推送给前端，返回本次推送的结果。
     *
     * @return JsonResponse 统一成功响应，data 为本次推送的磁盘指标/结果
     *
     * 为什么：推送（采集 + WebSocket 广播）逻辑集中在 DiskService，
     *         便于被定时任务与本控制器共用同一套采集/广播实现。
     */
    public function push(): JsonResponse
    {
        // 委派服务层执行「采集当前磁盘指标 + WebSocket 广播」，并回传推送结果
        return $this->success($this->service->push());
    }
}
