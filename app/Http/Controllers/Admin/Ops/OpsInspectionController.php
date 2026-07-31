<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Models\OpsInspection;
use App\Services\Ops\OpsInspectionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 运维巡检控制器（Ops Inspection）。
 *
 * 作用：为运维中心的「系统巡检」功能提供 HTTP 接口，包括当前巡检概要、
 * 历史巡检记录分页、单次巡检详情、多天巡检趋势，以及手动触发一次全量巡检。
 * 巡检本身（各项检查项的执行、结果打分、序列化）由 OpsInspectionService 承担，
 * 控制器负责参数夹取、分页拼装与响应包装。
 *
 * 为什么：巡检结果既要能实时触发（run），又要能回溯历史（history/show/trend），
 * 因此把持久化模型 OpsInspection 的查询留在控制器、把计算逻辑留在 Service。
 */
class OpsInspectionController extends Controller
{
    // 引入统一 API 响应 Trait
    use ApiResponse;

    /**
     * 巡检概要。
     *
     * 作用：返回最近一次/当前巡检的汇总信息（整体健康度、异常项数量等）。
     *
     * @param  OpsInspectionService  $service  巡检服务
     * @return JsonResponse 巡检概要数据
     */
    public function summary(OpsInspectionService $service): JsonResponse
    {
        // 委托服务生成概要
        return $this->success($service->summary());
    }

    /**
     * 巡检历史（分页）。
     *
     * 作用：按 id 倒序分页返回历史巡检记录，每条记录以「摘要」形式序列化。
     *
     * @param  Request  $request  HTTP 请求，读取 per_page 每页条数
     * @param  OpsInspectionService  $service  巡检服务，负责单条记录序列化
     * @return JsonResponse 含 items（记录摘要列表）与 pagination（分页信息）的响应
     *
     * 为什么：per_page 被夹取在 [10, 100]，防止前端一次拉取过多数据拖垮接口；
     * 用 latest('id') 而非按时间字段排序，保证同秒内多条记录仍有稳定顺序。
     */
    public function history(Request $request, OpsInspectionService $service): JsonResponse
    {
        // 每页条数归一化到 10~100（默认 20），避免过小/过大分页
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        // 按主键 id 倒序（即最新在前）分页取巡检记录
        $records = OpsInspection::query()
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            // 将当前页每条记录交给 Service 序列化为「摘要」结构后收集为数组
            'items' => collect($records->items())
                ->map(fn (OpsInspection $record): array => $service->serializeSummary($record))
                ->values()
                ->all(),
            // 标准分页元信息，供前端渲染分页器
            'pagination' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    /**
     * 巡检详情。
     *
     * 作用：返回单条巡检记录的完整明细（各检查项逐项结果）。
     *
     * @param  OpsInspection  $record  路由模型绑定注入的巡检记录
     * @param  OpsInspectionService  $service  巡检服务，负责详情序列化
     * @return JsonResponse 巡检详情数据
     *
     * 为什么：借助 Laravel 路由模型绑定（Route Model Binding）直接注入 $record，
     * 省去手动 findOrFail，未命中时框架自动返回 404。
     */
    public function show(OpsInspection $record, OpsInspectionService $service): JsonResponse
    {
        // 委托服务序列化为详情结构
        return $this->success($service->serializeDetail($record));
    }

    /**
     * 巡检趋势。
     *
     * 作用：返回最近 N 天的巡检趋势分桶数据（按天聚合的健康度走势）。
     *
     * @param  Request  $request  HTTP 请求，读取 days 统计天数
     * @param  OpsInspectionService  $service  巡检服务，负责按天分桶聚合
     * @return JsonResponse 含 days 与 buckets（按天分桶）的响应
     *
     * 为什么：days 夹取在 [1, 90]（默认 14），限制趋势图的时间跨度与计算量。
     */
    public function trend(Request $request, OpsInspectionService $service): JsonResponse
    {
        // 统计天数归一化到 1~90（默认 14 天）
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            // 委托服务生成按天分桶的趋势数据
            'buckets' => $service->trend($days),
        ]);
    }

    /**
     * 手动触发巡检。
     *
     * 作用：立即执行一次「全量」巡检并返回本次巡检详情。
     *
     * @param  Request  $request  HTTP 请求，用于获取当前操作管理员
     * @param  OpsInspectionService  $service  巡检服务，负责执行巡检
     * @return JsonResponse 本次巡检的详情数据
     *
     * 为什么：以 'full'（全量范围）+ 'manual'（手动触发来源）标记本次巡检，
     * 并传入当前管理员作为触发人，便于历史记录中区分定时巡检与人工巡检。
     */
    public function run(Request $request, OpsInspectionService $service): JsonResponse
    {
        // 执行全量巡检，触发来源标记为 manual，记录触发管理员
        $record = $service->run('full', 'manual', $request->user('admin'));

        // 返回本次新生成记录的完整详情
        return $this->success($service->serializeDetail($record));
    }
}
