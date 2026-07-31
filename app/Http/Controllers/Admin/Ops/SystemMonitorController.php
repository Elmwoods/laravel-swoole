<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OpsMetricSampleService;
use App\Services\Ops\SystemMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统监控控制器
 * -------------------------------------------------
 * 作用：运维后台「系统监控」的 HTTP 入口，提供系统实时状态快照
 *       （CPU/内存/负载等）以及系统指标的多天历史趋势查询。
 * 说明：实时指标采集由 SystemMonitorService 负责，历史采样的按天聚合
 *       由 OpsMetricSampleService 负责，控制器仅做参数收敛与响应包装。
 * -------------------------------------------------
 */
class SystemMonitorController extends Controller
{
    // 引入统一的 API 响应封装（success 等辅助方法）
    use ApiResponse;

    /**
     * 作用：构造函数，注入系统监控服务。
     *
     * @param  SystemMonitorService  $service  系统实时状态服务（只读依赖，容器自动注入）
     * @return void
     */
    public function __construct(
        private readonly SystemMonitorService $service
    ) {}

    /**
     * 系统状态
     *
     * 作用：返回系统当前状态快照（如 CPU、内存、负载等实时指标）。
     *
     * @return JsonResponse 统一成功响应，data 为系统实时状态
     */
    public function summary()
    {
        // 委派服务层采集系统实时状态并统一包装响应
        return $this->success(
            $this->service->summary()
        );
    }

    /**
     * 系统指标多天趋势（按天平均）。
     *
     * 作用：返回最近 N 天的系统指标趋势，按天聚合（平均）形成时间桶。
     *
     * @param  Request  $request  HTTP 请求，读取查询参数 days（天数）
     * @param  OpsMetricSampleService  $samples  指标采样聚合服务（按天聚合趋势）
     * @return JsonResponse 统一成功响应，data.days 为实际天数，data.buckets 为按天趋势桶
     *
     * 为什么：days 用 min(90, max(1, ...)) 双向夹取，把入参限制在 [1,90] 天，
     *         既防止 0/负数导致空区间，也避免超大天数拖垮查询与前端渲染；缺省 14 天。
     */
    public function metricsTrend(Request $request, OpsMetricSampleService $samples): JsonResponse
    {
        // 将请求的天数夹取到 [1,90] 区间（默认 14），防止越界或异常入参
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            // 委派采样服务按天聚合出趋势桶（每天一个平均值）
            'buckets' => $samples->trend($days),
        ]);
    }
}
