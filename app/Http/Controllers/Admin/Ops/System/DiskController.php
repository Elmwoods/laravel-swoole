<?php

namespace App\Http\Controllers\Admin\Ops\System;

use App\Http\Controllers\Controller;
use App\Services\Ops\System\DiskService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * DiskController
 * -------------------------------------------------
 * 提供磁盘监控 API
 * -------------------------------------------------
 * 作用：运维后台「磁盘监控」的 HTTP 入口，返回各挂载点的使用率、
 *       容量、剩余空间等汇总指标（含阈值判定后的健康状态）。
 * 说明：磁盘容量采集、使用率计算与阈值（warning/critical）判定
 *       均封装在 DiskService，控制器只负责委派与统一响应。
 */
class DiskController extends Controller
{
    // 引入统一的 API 响应封装（success 等辅助方法）
    use ApiResponse;

    /**
     * 作用：构造函数，注入磁盘监控服务。
     *
     * @param  DiskService  $diskService  磁盘指标采集/汇总服务（容器自动注入）
     * @return void
     */
    public function __construct(
        protected DiskService $diskService
    ) {}

    /**
     * 获取磁盘使用率
     *
     * GET /admin/ops/system/disk
     *
     * 作用：返回磁盘使用率汇总（各挂载点用量与阈值健康状态）。
     *
     * @return JsonResponse 统一成功响应，data 为磁盘汇总指标
     *
     * 为什么：使用率阈值判定属于可复用业务逻辑，下沉到 summary()，控制器保持轻薄。
     */
    public function index(): JsonResponse
    {
        // 委派服务层汇总磁盘用量并按阈值给出健康状态，再统一包装响应
        return $this->success($this->diskService->summary());
    }
}
