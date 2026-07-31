<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\SecurityOverviewService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 安全概览控制器（Security Overview）
 * -------------------------------------------------
 * 作用：运维后台「安全概览」页面的 HTTP 入口，负责把安全相关的
 *       各类指标（如告警统计、异常登录、风险事件等）聚合后返回给前端。
 * 说明：控制器本身不做任何业务计算，所有聚合逻辑都下沉到
 *       SecurityOverviewService，控制器只负责「接收请求 -> 委派服务 -> 统一响应」。
 * -------------------------------------------------
 */
class SecurityOverviewController extends Controller
{
    // 引入统一的 API 响应封装（success/error 等辅助方法）
    use ApiResponse;

    /**
     * 作用：返回安全概览的聚合数据。
     *
     * @param  SecurityOverviewService  $service  安全概览聚合服务（由容器自动注入）
     * @return JsonResponse 统一格式的成功响应，data 为聚合后的安全概览
     *
     * 为什么：聚合逻辑（多来源指标汇总）复杂且可复用，放在 Service 中，
     *         控制器保持「瘦」，仅委派并用 success() 包装结果。
     */
    public function overview(SecurityOverviewService $service): JsonResponse
    {
        // 委派给服务层完成安全指标聚合，并用统一响应格式包装
        return $this->success($service->overview());
    }
}
