<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertPresetStoreRequest;
use App\Services\Ops\OpsAlertPresetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 告警中心筛选预设（owner-scoped）。审计由路由的 admin.audit:ops.alerts,preset_* 中间件记录。
 *
 * 作用：承载告警列表「筛选预设」的增删查路由——列出当前管理员的预设、保存预设、删除预设。
 * 「为什么」：
 *   - owner-scoped：预设按创建者隔离，所有操作都带 $request->user('admin') 做归属过滤，互不可见；
 *   - 审计：写操作的审计日志由路由上的 admin.audit 中间件统一记录，Controller 无需手写。
 */
class AlertPresetController extends Controller
{
    use ApiResponse;

    /**
     * 作用：注入预设服务。
     *
     * @param  OpsAlertPresetService  $presets  负责按 owner 读写预设的服务
     * @return void
     */
    public function __construct(
        private readonly OpsAlertPresetService $presets,
    ) {}

    /**
     * 作用：列出当前管理员拥有的全部筛选预设。
     *
     * @param  Request  $request  当前请求，用于取出 owner（当前管理员）
     * @return JsonResponse 含 items 预设数组
     *
     * 「为什么」：以当前管理员为 owner 作用域，天然只返回本人预设。
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success([
            // owner 作用域：仅列出当前管理员的预设
            'items' => $this->presets->list($request->user('admin')),
        ]);
    }

    /**
     * 作用：为当前管理员保存（新建或按名覆盖）一个筛选预设。
     *
     * @param  AlertPresetStoreRequest  $request  已校验的 name（预设名）与 filters（筛选条件），并携带 owner
     * @return JsonResponse 含 preset（保存后的 id/名称/筛选/时间）
     *
     * 「为什么」：filters 强转数组并默认 []，容忍前端提交空筛选（即「全部」预设）。
     */
    public function store(AlertPresetStoreRequest $request): JsonResponse
    {
        // 委派 Service 落库预设：绑定当前管理员为 owner，filters 缺省兜底为空数组
        $preset = $this->presets->save(
            $request->user('admin'),
            (string) $request->validated('name'),
            (array) $request->validated('filters', []),
        );

        return $this->success([
            'preset' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'filters' => (array) $preset->filters,
                'created_at' => optional($preset->created_at)->toDateTimeString(),
            ],
        ]);
    }

    /**
     * 作用：删除当前管理员的一个预设，返回是否成功。
     *
     * @param  Request  $request  当前请求，用于取出 owner 做归属校验
     * @param  int  $preset  待删除预设的主键 id
     * @return JsonResponse 含 deleted 布尔结果
     *
     * 「为什么」：把 owner 传入 Service，确保只能删自己的预设，防止越权删除他人预设。
     */
    public function destroy(Request $request, int $preset): JsonResponse
    {
        return $this->success([
            // owner 作用域删除：Service 内校验该预设确属当前管理员
            'deleted' => $this->presets->delete($request->user('admin'), $preset),
        ]);
    }
}
