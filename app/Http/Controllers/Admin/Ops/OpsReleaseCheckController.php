<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Models\OpsReleaseCheck;
use App\Services\Ops\OpsReleaseCheckHistoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 发布前检查控制器（Ops Release Check）。
 *
 * 作用：为运维中心的「发布准入检查（放行门禁）」提供 HTTP 接口，包括：
 * 概览当前检查状态、立即执行一次发布检查并落库、分页查询历史检查记录、
 * 查看单次检查详情。发布检查用于在上线前一次性核对系统各项前置条件是否满足。
 * 具体的检查项执行与结果落库由 OpsReleaseCheckHistoryService 完成。
 *
 * 为什么：发布检查是「门禁」性质——每次执行都要留痕（runAndRecord），
 * 以便回溯某次上线时系统是否处于健康/放行状态，因此历史记录不可或缺。
 */
class OpsReleaseCheckController extends Controller
{
    // 引入统一 API 响应 Trait
    use ApiResponse;

    /**
     * 发布检查概览。
     *
     * 作用：返回最近一次发布检查的概览（是否通过、失败项等）。
     *
     * @param  OpsReleaseCheckHistoryService  $service  发布检查历史服务
     * @return JsonResponse 概览数据
     */
    public function overview(OpsReleaseCheckHistoryService $service): JsonResponse
    {
        // 委托服务生成概览
        return $this->success($service->overview());
    }

    /**
     * 执行发布检查。
     *
     * 作用：立即运行一次发布准入检查，将结果落库，并返回本次检查详情。
     *
     * @param  Request  $request  HTTP 请求，用于获取当前操作管理员
     * @param  OpsReleaseCheckHistoryService  $service  发布检查历史服务
     * @return JsonResponse 本次检查的详情数据
     *
     * 为什么：runAndRecord 同时完成「执行 + 记录」，把触发管理员一并写入，
     * 保证每一次放行判断都有可审计的历史条目。
     */
    public function run(Request $request, OpsReleaseCheckHistoryService $service): JsonResponse
    {
        // 执行发布检查并落库，记录触发管理员
        $record = $service->runAndRecord($request->user('admin'));

        // 返回本次检查记录的完整详情
        return $this->success($service->serializeDetail($record));
    }

    /**
     * 发布检查历史（分页）。
     *
     * 作用：按 id 倒序分页返回历史发布检查记录，每条以「摘要」形式序列化。
     *
     * @param  Request  $request  HTTP 请求，读取 per_page 每页条数
     * @param  OpsReleaseCheckHistoryService  $service  发布检查历史服务，负责序列化
     * @return JsonResponse 含 items 与 pagination 的响应
     *
     * 为什么：per_page 夹取在 [10, 100]，避免过大分页；latest('id') 保证稳定倒序。
     */
    public function history(Request $request, OpsReleaseCheckHistoryService $service): JsonResponse
    {
        // 每页条数归一化到 10~100（默认 20）
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        // 按主键 id 倒序（最新在前）分页取发布检查记录
        $records = OpsReleaseCheck::query()
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            // 将当前页每条记录序列化为摘要结构后收集为数组
            'items' => collect($records->items())
                ->map(fn (OpsReleaseCheck $record): array => $service->serializeSummary($record))
                ->values()
                ->all(),
            // 标准分页元信息
            'pagination' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    /**
     * 发布检查详情。
     *
     * 作用：返回单条发布检查记录的完整明细（各检查项逐项结果）。
     *
     * @param  OpsReleaseCheck  $record  路由模型绑定注入的发布检查记录
     * @param  OpsReleaseCheckHistoryService  $service  发布检查历史服务，负责详情序列化
     * @return JsonResponse 检查详情数据
     *
     * 为什么：使用路由模型绑定直接注入记录，未命中自动 404。
     */
    public function show(OpsReleaseCheck $record, OpsReleaseCheckHistoryService $service): JsonResponse
    {
        // 委托服务序列化为详情结构
        return $this->success($service->serializeDetail($record));
    }
}
