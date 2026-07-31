<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\ShiftHandoverStoreRequest;
use App\Services\Ops\ShiftHandoverService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * 交接班控制器（Shift Handover）
 * -------------------------------------------------
 * 作用：运维值班「交接班记录」的 HTTP 入口，负责列出历史交接记录，
 *       以及在换班时持久化一条新的交接单（含交接人、接班人、未处理告警数等）。
 * 说明：交接单的落库逻辑（快照当前未关闭告警数量、校验接班人等）
 *       全部封装在 ShiftHandoverService，控制器只做请求转发与响应包装。
 * -------------------------------------------------
 */
class ShiftHandoverController extends Controller
{
    // 引入统一的 API 响应封装（success 等辅助方法）
    use ApiResponse;

    /**
     * 作用：构造函数，注入交接班服务。
     *
     * @param  ShiftHandoverService  $handovers  交接班业务服务（只读依赖，容器自动注入）
     * @return void
     *
     * 为什么：使用构造函数属性提升 + readonly，保证依赖不可变，避免运行期被替换。
     */
    public function __construct(private readonly ShiftHandoverService $handovers) {}

    /**
     * 作用：返回全部交接班记录列表。
     *
     * @return JsonResponse 统一成功响应，data.items 为交接记录集合
     */
    public function index(): JsonResponse
    {
        // 委派服务层查询交接记录列表，并以 items 键统一包装返回
        return $this->success(['items' => $this->handovers->list()]);
    }

    /**
     * 作用：创建（持久化）一条新的交接班记录。
     *
     * @param  ShiftHandoverStoreRequest  $request  已通过表单校验的交接单请求
     * @return JsonResponse 成功时返回新建交接单摘要；接班人非法时返回 422 校验错误
     *
     * 为什么：接班人（to_assignee）合法性属于业务规则校验，服务层用
     *         InvalidArgumentException 表达，这里捕获后转成前端可识别的 422 表单错误。
     */
    public function store(ShiftHandoverStoreRequest $request): JsonResponse
    {
        try {
            // 委派服务层落库：传入已校验数据与当前 admin 守卫下的操作者，
            // 服务内部会快照「未处理告警数」等交接现场信息
            $handover = $this->handovers->create($request->validated(), $request->user('admin'));
        } catch (InvalidArgumentException $e) {
            // 业务校验失败（如接班人无效）：返回 422 并将错误挂到 to_assignee 字段上，
            // 以便前端表单能精确定位到出错输入框
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['to_assignee' => [$e->getMessage()]],
            ], 422);
        }

        // 落库成功：只回传前端需要的交接单关键字段（避免暴露整个模型）
        return $this->success(['handover' => [
            'id' => $handover->id,
            'from_assignee' => $handover->from_assignee,
            'to_assignee' => $handover->to_assignee,
            // 未处理告警数强制转 int，保证 JSON 类型稳定（数据库可能返回字符串）
            'open_alert_count' => (int) $handover->open_alert_count,
        ]]);
    }
}
