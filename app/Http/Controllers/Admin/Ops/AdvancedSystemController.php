<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\AdvancedSystemMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 高级系统监控
 *
 * 作用：承载 Ops Center「高级系统监控」的只读路由，目前仅一条 summary——
 *   返回进阶系统指标概览（如进程/连接/资源等）。路由通常位于 ops 前缀之下。
 * 「为什么」：控制器保持薄层，实际指标采集与聚合全部在 AdvancedSystemMonitorService 中完成。
 */
class AdvancedSystemController extends Controller
{
    use ApiResponse;

    /**
     * 作用：注入高级系统监控服务。
     *
     * @param  AdvancedSystemMonitorService  $service  负责采集并汇总高级系统指标的服务
     * @return void
     */
    public function __construct(
        private readonly AdvancedSystemMonitorService $service
    ) {}

    /**
     * 作用：返回高级系统监控指标概览。
     *
     * @return JsonResponse Service::summary() 汇总出的指标结构（经统一成功包装）
     */
    public function summary()
    {
        // 委派 Service 采集并聚合高级系统指标
        return $this->success(
            $this->service->summary()
        );
    }
}
