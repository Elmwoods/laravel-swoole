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

class AdminIpRuleController extends Controller
{
    public function __construct(
        private readonly AdminIpAccessService $ipAccess,
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success([
            'settings' => $this->ipAccess->settings(),
            'rules' => $this->ipAccess->rules(),
            'client_ip' => $request->ip(),
            // 自动封禁的阈值/窗口/时长走 env（只读展示），启用开关在 settings.auto_ban_enabled。
            'auto_ban' => [
                'threshold' => (int) config('ops.security.auto_ban.threshold', 10),
                'window_minutes' => (int) config('ops.security.auto_ban.window_minutes', 10),
                'ban_minutes' => (int) config('ops.security.auto_ban.ban_minutes', 60),
            ],
        ]);
    }

    public function store(AdminIpRuleStoreRequest $request): JsonResponse
    {
        $admin = $request->user('admin');

        try {
            $rule = $this->ipAccess->createRule(
                (string) $request->validated('type'),
                (string) $request->validated('cidr'),
                $request->validated('label'),
                $admin,
            );
        } catch (InvalidArgumentException $e) {
            $this->audit->record($request, 'admin.security', 'ip_rule_create', 'failure', 422, admin: $admin, payload: [
                'type' => $request->validated('type'),
                'cidr' => $request->validated('cidr'),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['cidr' => [$e->getMessage()]],
            ], 422);
        }

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

    public function update(Request $request, int $rule): JsonResponse
    {
        $admin = $request->user('admin');
        $active = $request->boolean('is_active');
        $updated = $this->ipAccess->toggleRule($rule, $active);

        $this->audit->record($request, 'admin.security', 'ip_rule_toggle', $updated ? 'success' : 'failure', $updated ? 200 : 404, admin: $admin, payload: [
            'rule_id' => $rule,
            'is_active' => $active,
        ]);

        return $this->success([
            'updated' => $updated,
        ]);
    }

    public function destroy(Request $request, int $rule): JsonResponse
    {
        $admin = $request->user('admin');
        $deleted = $this->ipAccess->deleteRule($rule);

        $this->audit->record($request, 'admin.security', 'ip_rule_delete', $deleted ? 'success' : 'failure', $deleted ? 200 : 404, admin: $admin, payload: [
            'rule_id' => $rule,
        ]);

        return $this->success([
            'deleted' => $deleted,
        ]);
    }

    public function updateSettings(AdminIpAccessSettingsRequest $request): JsonResponse
    {
        $admin = $request->user('admin');
        $settings = $this->ipAccess->updateSettings($request->validated());

        $this->audit->record($request, 'admin.security', 'ip_access_settings', 'success', 200, admin: $admin, payload: $settings);

        return $this->success([
            'settings' => $settings,
        ]);
    }
}
