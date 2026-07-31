<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\OnCallShiftStoreRequest;
use App\Services\Ops\OnCallRotationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * 值班排班控制器（On-Call）。
 *
 * 作用：为运维中心（Ops Center）提供值班班次（shift）的 HTTP 接口，
 * 覆盖排班列表查询、创建新班次、启用/停用切换以及删除。
 * 控制器本身不含业务逻辑，全部委托给 OnCallRotationService（轮值服务）处理，
 * 自己只负责参数解析与统一的 JSON 响应包装。
 *
 * 为什么：将「轮值规则、时间校验、当前值班人计算」等复杂逻辑收敛到 Service 层，
 * 让控制器保持瘦身，便于复用与单元测试。
 */
class OnCallController extends Controller
{
    // 引入统一的 API 响应 Trait，提供 success() 等便捷方法
    use ApiResponse;

    /**
     * 构造函数：注入值班轮值服务。
     *
     * @param  OnCallRotationService  $rotation  值班轮值服务，承载全部排班业务逻辑
     *
     * 为什么：通过构造函数注入（依赖注入容器自动解析），
     * 使控制器与具体实现解耦，方便测试时替换为 mock。
     */
    public function __construct(
        private readonly OnCallRotationService $rotation,
    ) {}

    /**
     * 值班总览列表。
     *
     * 作用：返回全部值班班次列表，以及「当前正在值班」的人员/班次。
     *
     * @return JsonResponse 包含 items（班次列表）与 current（当前值班）的统一响应
     */
    public function index(): JsonResponse
    {
        return $this->success([
            // 全部排班班次列表
            'items' => $this->rotation->list(),
            // 依据当前时间点计算出的「此刻正在值班」的班次
            'current' => $this->rotation->currentOnCall(),
        ]);
    }

    /**
     * 创建值班班次。
     *
     * 作用：接收经表单校验后的班次数据，创建一条新的值班排班记录。
     *
     * @param  OnCallShiftStoreRequest  $request  已完成校验的创建请求（含起止时间、值班人等字段）
     * @return JsonResponse 成功返回新班次 id；时间区间非法时返回 422 校验错误
     *
     * 为什么：起止时间的合法性（如 ends_at 必须晚于 starts_at）由 Service 层抛出
     * InvalidArgumentException 表达，这里捕获并转换成前端可识别的 422 校验错误结构。
     */
    public function store(OnCallShiftStoreRequest $request): JsonResponse
    {
        try {
            // 委托 Service 创建班次，同时传入当前登录管理员作为操作人（审计/创建者）
            $shift = $this->rotation->create($request->validated(), $request->user('admin'));
        } catch (InvalidArgumentException $e) {
            // Service 抛出的业务校验异常（如结束时间早于开始时间）→ 转为 422 表单错误响应
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['ends_at' => [$e->getMessage()]],
            ], 422);
        }

        return $this->success(['shift' => ['id' => $shift->id]]);
    }

    /**
     * 启用/停用值班班次。
     *
     * 作用：切换指定班次的 is_active 状态（上线或下线该排班）。
     *
     * @param  Request  $request  HTTP 请求，读取 is_active 布尔标志
     * @param  int  $shift  目标班次 id（路由参数）
     * @return JsonResponse 返回切换后的结果
     *
     * 为什么：用 boolean() 强制把请求值规整为布尔，避免 "0"/"false" 等字符串歧义。
     */
    public function update(Request $request, int $shift): JsonResponse
    {
        // 委托 Service 切换班次启用状态；is_active 经 boolean() 归一化为 true/false
        return $this->success(['updated' => $this->rotation->toggle($shift, $request->boolean('is_active'))]);
    }

    /**
     * 删除值班班次。
     *
     * 作用：删除指定 id 的值班排班记录。
     *
     * @param  int  $shift  目标班次 id（路由参数）
     * @return JsonResponse 返回删除结果
     */
    public function destroy(int $shift): JsonResponse
    {
        // 委托 Service 执行删除
        return $this->success(['deleted' => $this->rotation->delete($shift)]);
    }
}
