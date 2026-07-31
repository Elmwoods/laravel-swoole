<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertSilenceStoreRequest;
use App\Services\Ops\AlertSilenceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * 告警静默(silence)控制器。
 *
 * 作用：承载告警静默窗口的增删改查路由——列出静默规则、新建静默窗、启停某静默、删除静默。
 *   路由通常位于 ops/alerts/silences 之下。
 * 「为什么」：静默是「在给定时间窗内、对匹配条件的告警不发通知」的抑制机制，
 *   用于计划内维护或已知故障期间避免通知刷屏；到期或停用后恢复正常触发。
 */
class AlertSilenceController extends Controller
{
    use ApiResponse;

    /**
     * 作用：注入静默服务。
     *
     * @param  AlertSilenceService  $silences  负责静默窗口读写、启停与生效判定的服务
     * @return void
     */
    public function __construct(
        private readonly AlertSilenceService $silences,
    ) {}

    /**
     * 作用：返回全部静默规则，以及当前正在生效的静默集合。
     *
     * @return JsonResponse 含 items（全部静默）与 active（当前生效的静默）
     *
     * 「为什么」：同时给出「全部」与「生效中」，前端既能管理列表又能高亮当下正被压制的窗口。
     */
    public function index(): JsonResponse
    {
        return $this->success([
            'items' => $this->silences->list(),
            // 当前时间落在窗口内且启用中的静默
            'active' => $this->silences->activeSilences(),
        ]);
    }

    /**
     * 作用：新建一个静默窗口（含匹配条件与起止时间）。
     *
     * @param  AlertSilenceStoreRequest  $request  已校验的静默参数（匹配条件、starts_at/ends_at 等），并携带创建管理员
     * @return JsonResponse 成功含 silence.id；时间区间非法时返回 422
     *
     * 「为什么」：Service 对时间窗做业务校验（如 ends_at 必须晚于当前/起点），
     *   非法时抛 InvalidArgumentException，这里捕获并转成挂在 ends_at 字段上的 422 表单错误，便于前端定位。
     */
    public function store(AlertSilenceStoreRequest $request): JsonResponse
    {
        try {
            // 委派 Service 创建静默；绑定创建管理员，内部会校验时间窗合法性
            $silence = $this->silences->create($request->validated(), $request->user('admin'));
        } catch (InvalidArgumentException $e) {
            // 时间窗非法：转成 422，并把错误信息挂到 ends_at 字段上供前端表单展示
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['ends_at' => [$e->getMessage()]],
            ], 422);
        }

        return $this->success(['silence' => ['id' => $silence->id]]);
    }

    /**
     * 作用：启用/停用某条静默，返回是否更新成功。
     *
     * @param  Request  $request  当前请求，读取 is_active 目标状态
     * @param  int  $silence  目标静默的主键 id
     * @return JsonResponse 含 updated 布尔结果
     *
     * 「为什么」：停用即提前结束静默压制（无需删除记录，保留可复用），故用 toggle 而非删除。
     */
    public function update(Request $request, int $silence): JsonResponse
    {
        // 按 is_active 切换该静默的启停
        $updated = $this->silences->toggle($silence, $request->boolean('is_active'));

        return $this->success(['updated' => $updated]);
    }

    /**
     * 作用：删除一条静默规则。
     *
     * @param  int  $silence  目标静默的主键 id
     * @return JsonResponse 含 deleted 布尔结果
     */
    public function destroy(int $silence): JsonResponse
    {
        return $this->success(['deleted' => $this->silences->delete($silence)]);
    }
}
