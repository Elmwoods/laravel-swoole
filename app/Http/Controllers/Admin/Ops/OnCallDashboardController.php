<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OnCallDashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 值班看板控制器（On-Call Dashboard）。
 *
 * 作用：为运维中心的值班可视化看板提供聚合数据接口，
 * 例如指定时间窗口内的值班覆盖率、交接情况、告警关联等概览统计。
 * 与 OnCallController（增删改班次）不同，本控制器只读、只做统计展示。
 *
 * 为什么：把「多天窗口的统计聚合」逻辑放在 OnCallDashboardService 中，
 * 控制器仅负责解析并夹取（clamp）时间窗口参数后转交给服务。
 */
class OnCallDashboardController extends Controller
{
    // 引入统一 API 响应 Trait
    use ApiResponse;

    /**
     * 值班看板概览。
     *
     * 作用：返回最近 N 天（days）的值班看板聚合数据。
     *
     * @param  Request  $request  HTTP 请求，读取 days 查询参数（统计天数）
     * @param  OnCallDashboardService  $service  值班看板服务，负责聚合统计
     * @return JsonResponse 看板概览数据的统一响应
     *
     * 为什么：days 需被夹取在 [1, 90] 区间内，防止前端传入 0、负数或超大值
     * 导致空结果或昂贵的全表扫描；默认取 7 天。
     */
    public function overview(Request $request, OnCallDashboardService $service): JsonResponse
    {
        // 将 days 归一化并限制在 1~90 天之间（默认 7 天），避免非法或过大范围
        $days = min(90, max(1, (int) $request->integer('days', 7)));

        // 委托看板服务按窗口天数聚合并返回
        return $this->success($service->overview($days));
    }
}
