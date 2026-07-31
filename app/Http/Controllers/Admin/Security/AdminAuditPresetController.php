<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminAuditPresetStoreRequest;
use App\Services\Admin\AdminAuditPresetService;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台审计筛选预设控制器。
 *
 * 作用：让管理员把常用的审计日志筛选条件保存为「预设」以便复用，服务的路由包括：
 *  - GET    audit-presets        列出当前管理员的全部预设（index）
 *  - POST   audit-presets        新建一条预设（store）
 *  - DELETE audit-presets/{id}   删除指定预设（destroy）
 *
 * 「为什么」：预设按管理员维度隔离（每人只见/管自己的），增删动作均写审计日志便于追溯。
 */
class AdminAuditPresetController extends Controller
{
    /**
     * 构造函数：注入预设服务与审计服务。
     *
     * @param  AdminAuditPresetService  $presets  审计筛选预设的增删查服务
     * @param  AdminAuditService  $audit  审计日志服务，记录预设的创建/删除
     * @return void
     */
    public function __construct(
        private readonly AdminAuditPresetService $presets,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * 作用：列出当前管理员保存的全部审计筛选预设。
     *
     * @param  Request  $request  当前请求（用于取 admin 守卫用户）
     * @return JsonResponse items 为预设列表
     *
     * 「为什么」：以当前登录管理员为范围，保证只返回本人的预设。
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success([
            'items' => $this->presets->list($request->user('admin')),
        ]);
    }

    /**
     * 作用：为当前管理员新建一条审计筛选预设（名称 + 筛选条件）。
     *
     * @param  AdminAuditPresetStoreRequest  $request  已校验的请求（含 name 与 filters）
     * @return JsonResponse 新建的预设（id/name/filters/created_at）
     *
     * 「为什么」：创建成功后写一条 preset_create 审计日志，携带预设 ID 与名称便于追踪。
     */
    public function store(AdminAuditPresetStoreRequest $request): JsonResponse
    {
        $admin = $request->user('admin');
        // 委托预设服务持久化：绑定当前管理员，保存名称与筛选条件数组。
        $preset = $this->presets->save(
            $admin,
            (string) $request->validated('name'),
            (array) $request->validated('filters', []),
        );

        // 记录创建审计。
        $this->audit->record($request, 'admin.audit', 'preset_create', 'success', 200, admin: $admin, payload: [
            'preset_id' => $preset->id,
            'name' => $preset->name,
        ]);

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
     * 作用：删除当前管理员名下指定的审计筛选预设。
     *
     * @param  Request  $request  当前请求（用于取 admin 守卫用户）
     * @param  int  $preset  待删除的预设 ID
     * @return JsonResponse 布尔字段 deleted 表示是否删除成功
     *
     * 「为什么」：删除时以当前管理员为约束，避免误删他人预设；删除结果决定审计 result 与状态码。
     */
    public function destroy(Request $request, int $preset): JsonResponse
    {
        $admin = $request->user('admin');
        // 仅删除属于当前管理员的预设（服务内校验归属），返回是否命中删除。
        $deleted = $this->presets->delete($admin, $preset);

        // 按删除结果记审计（成功 200 / 未找到 404）。
        $this->audit->record($request, 'admin.audit', 'preset_delete', $deleted ? 'success' : 'failure', $deleted ? 200 : 404, admin: $admin, payload: [
            'preset_id' => $preset,
        ]);

        return $this->success([
            'deleted' => $deleted,
        ]);
    }
}
