<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminIpAccessSettingsRequest;
use App\Http\Requests\Admin\Security\AdminIpRuleStoreRequest;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminIpAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * 后台 IP 访问规则控制器。
 *
 * 作用：管理后台的 IP 准入策略（黑白名单 CIDR 规则）与相关设置，服务的路由包括：
 *  - GET    ip-rules             返回设置、规则列表、当前客户端 IP 及自动封禁只读参数（index）
 *  - POST   ip-rules             新增一条 IP 规则（allow/deny 的 CIDR）（store）
 *  - PATCH  ip-rules/{id}        启用/停用某条规则（update）
 *  - DELETE ip-rules/{id}        删除某条规则（destroy）
 *  - PUT    ip-rules/settings    更新 IP 准入总体设置（updateSettings）
 *
 * 「为什么」：这些规则直接决定谁能访问后台（见 AdminAuthController::login 的 IP 准入关卡），
 * 属高危配置，故每个写操作都写审计；CIDR 非法时返回 422 而非抛异常。
 */
class AdminIpRuleController extends Controller
{
    /**
     * 构造函数：注入 IP 准入服务与审计服务。
     *
     * @param  AdminIpAccessService  $ipAccess  IP 准入规则/设置的读写服务
     * @param  AdminAuditService  $audit  审计日志服务，记录规则与设置变更
     * @return void
     */
    public function __construct(
        private readonly AdminIpAccessService $ipAccess,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * 作用：返回 IP 准入的全部展示数据：设置、规则列表、当前访问者 IP 与自动封禁参数。
     *
     * @param  Request  $request  当前请求（用于回显 client_ip，方便管理员把自己加入白名单）
     * @return JsonResponse settings/rules/client_ip/auto_ban 四部分
     *
     * 「为什么」：自动封禁的阈值/窗口/时长来自 env 只读展示（不可在页面改），
     * 唯一可切换的是 settings.auto_ban_enabled 开关，避免误改风控核心阈值。
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success([
            // 当前 IP 准入设置（如是否启用白名单模式、自动封禁开关等）。
            'settings' => $this->ipAccess->settings(),
            // 全部已配置的 IP 规则（allow/deny + CIDR）。
            'rules' => $this->ipAccess->rules(),
            // 回显当前请求来源 IP，便于管理员将自身 IP 加入规则以防自锁。
            'client_ip' => $request->ip(),
            // 自动封禁的阈值/窗口/时长走 env（只读展示），启用开关在 settings.auto_ban_enabled。
            'auto_ban' => [
                'threshold' => (int) config('ops.security.auto_ban.threshold', 10),
                'window_minutes' => (int) config('ops.security.auto_ban.window_minutes', 10),
                'ban_minutes' => (int) config('ops.security.auto_ban.ban_minutes', 60),
            ],
        ]);
    }

    /**
     * 作用：新增一条 IP 准入规则（type=allow/deny，cidr 为网段，可带标签）。
     *
     * @param  AdminIpRuleStoreRequest  $request  已校验的请求（type/cidr/label）
     * @return JsonResponse 成功返回新建规则；CIDR 非法返回 422
     *
     * 「为什么」：CIDR 的合法性由服务在 createRule 内判定并抛 InvalidArgumentException，
     * 这里捕获后转成 422 校验错误响应（而非 500），并记一条失败审计。
     */
    public function store(AdminIpRuleStoreRequest $request): JsonResponse
    {
        $admin = $request->user('admin');

        try {
            // 委托服务创建规则：校验并解析 CIDR、落库并关联操作管理员。
            $rule = $this->ipAccess->createRule(
                (string) $request->validated('type'),
                (string) $request->validated('cidr'),
                $request->validated('label'),
                $admin,
            );
        } catch (InvalidArgumentException $e) {
            // CIDR 非法：记失败审计，并把异常消息转成 422 表单校验错误返回。
            $this->audit->record($request, 'admin.security', 'ip_rule_create', 'failure', 422, admin: $admin, payload: [
                'type' => $request->validated('type'),
                'cidr' => $request->validated('cidr'),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['cidr' => [$e->getMessage()]],
            ], 422);
        }

        // 创建成功：记成功审计（含规则 ID/类型/网段）。
        $this->audit->record($request, 'admin.security', 'ip_rule_create', 'success', 200, admin: $admin, payload: [
            'rule_id' => $rule->id,
            'type' => $rule->type,
            'cidr' => $rule->cidr,
        ]);

        return $this->success([
            'rule' => [
                'id' => $rule->id,
                'type' => $rule->type,
                'cidr' => $rule->cidr,
                'label' => $rule->label,
                'is_active' => (bool) $rule->is_active,
                'created_at' => optional($rule->created_at)->toDateTimeString(),
            ],
        ]);
    }

    /**
     * 作用：启用或停用指定的 IP 规则。
     *
     * @param  Request  $request  当前请求（读取 is_active 布尔值）
     * @param  int  $rule  目标规则 ID
     * @return JsonResponse 布尔字段 updated 表示是否更新成功
     *
     * 「为什么」：停用而非删除可保留规则历史，便于临时开关；结果决定审计 result 与状态码。
     */
    public function update(Request $request, int $rule): JsonResponse
    {
        $admin = $request->user('admin');
        $active = $request->boolean('is_active');
        // 切换该规则的启用状态，返回是否命中更新。
        $updated = $this->ipAccess->toggleRule($rule, $active);

        $this->audit->record($request, 'admin.security', 'ip_rule_toggle', $updated ? 'success' : 'failure', $updated ? 200 : 404, admin: $admin, payload: [
            'rule_id' => $rule,
            'is_active' => $active,
        ]);

        return $this->success([
            'updated' => $updated,
        ]);
    }

    /**
     * 作用：删除指定的 IP 规则。
     *
     * @param  Request  $request  当前请求（用于取操作管理员并记审计）
     * @param  int  $rule  待删除的规则 ID
     * @return JsonResponse 布尔字段 deleted 表示是否删除成功
     */
    public function destroy(Request $request, int $rule): JsonResponse
    {
        $admin = $request->user('admin');
        // 删除规则，返回是否命中删除。
        $deleted = $this->ipAccess->deleteRule($rule);

        $this->audit->record($request, 'admin.security', 'ip_rule_delete', $deleted ? 'success' : 'failure', $deleted ? 200 : 404, admin: $admin, payload: [
            'rule_id' => $rule,
        ]);

        return $this->success([
            'deleted' => $deleted,
        ]);
    }

    /**
     * 作用：更新 IP 准入的整体设置（如白名单模式开关、自动封禁启用开关等）。
     *
     * @param  AdminIpAccessSettingsRequest  $request  已校验的设置请求
     * @return JsonResponse 更新后的完整设置
     *
     * 「为什么」：把更新后的 settings 作为审计 payload 落库，便于回溯「谁把哪些开关改成了什么」。
     */
    public function updateSettings(AdminIpAccessSettingsRequest $request): JsonResponse
    {
        $admin = $request->user('admin');
        // 委托服务持久化设置，返回归一化后的最新设置。
        $settings = $this->ipAccess->updateSettings($request->validated());

        $this->audit->record($request, 'admin.security', 'ip_access_settings', 'success', 200, admin: $admin, payload: $settings);

        return $this->success([
            'settings' => $settings,
        ]);
    }
}
