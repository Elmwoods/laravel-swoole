<?php

namespace Tests\Feature\Ops;

use App\Models\AdminRole;
use App\Models\OpsReleaseCheck;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10：发布前自检（Release Check）功能测试。
 *
 * 场景：运维在发布前可运行一组自检（环境配置、开关、密钥等），
 * 结果按 pass/warn/fail 汇总并落库形成历史。本测试文件覆盖：
 * 接口鉴权（未登录 401、无 ops.release.view 权限 403）、
 * 权限注册且仅分配给 ops_admin 角色、概览返回最近一次摘要（不含明细）、
 * 运行自检落库并写审计日志、以及历史/详情对敏感字段做脱敏。
 */
class PhaseTenReleaseCheckTest extends TestCase
{
    use RefreshDatabase;

    // 验证：未认证访问概览接口返回 401。
    public function test_release_check_overview_requires_authentication(): void
    {
        $this->getJson('/api/ops/release-check/overview')
            ->assertStatus(401);
    }

    // 验证：已登录但仅有 dashboard 权限（缺 ops.release.view）时，概览与运行接口均返回 403。
    public function test_release_check_routes_require_release_permission(): void
    {
        // 故意只授予 dashboard 权限，用于确认发布自检需要专门权限。
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);

        $this->getJson('/api/ops/release-check/overview')
            ->assertStatus(403);

        $this->postJson('/api/ops/release-check/run')
            ->assertStatus(403);
    }

    /**
     * 验证：同步默认权限后，ops.release.view 权限被注册，
     * 且仅挂给 ops_admin 角色，audit_viewer 角色不应拥有该权限。
     */
    public function test_release_check_permission_is_registered_and_assigned_to_ops_admin_only(): void
    {
        // 同步内置权限与角色映射到数据库。
        app(AdminPermissionRegistry::class)->syncDefaults();

        $this->assertDatabaseHas('admin_permissions', [
            'slug' => 'ops.release.view',
            'name' => '发布自检查看',
            'group' => 'ops',
        ]);

        $this->assertDatabaseHas('admin_roles', ['slug' => 'ops_admin']);
        $this->assertTrue(
            AdminRole::query()
                ->where('slug', 'ops_admin')
                ->whereHas('permissions', fn ($query) => $query->where('slug', 'ops.release.view'))
                ->exists(),
        );
        $this->assertFalse(
            AdminRole::query()
                ->where('slug', 'audit_viewer')
                ->whereHas('permissions', fn ($query) => $query->where('slug', 'ops.release.view'))
                ->exists(),
        );
    }

    /**
     * 验证：概览接口只返回最近一次自检的摘要与元信息（权限名、示例命令、最新记录状态），
     * 不触发完整自检，也不返回逐项 checks 明细。
     */
    public function test_release_check_overview_returns_latest_summary_without_running_full_check(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.release.view']);
        // 预置一条 warn 状态的历史记录（3 通过 / 1 警告），作为“最近一次”结果。
        $record = OpsReleaseCheck::query()->create([
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
            'status' => 'warn',
            'summary' => ['pass' => 3, 'warn' => 1, 'fail' => 0],
            'checks' => [['group' => '环境', 'name' => 'APP_DEBUG', 'status' => 'warn', 'message' => '调试模式已开启', 'hint' => '关闭调试']],
            'duration_ms' => 12,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->getJson('/api/ops/release-check/overview')
            ->assertOk()
            ->assertJsonPath('data.permission', 'ops.release.view')
            ->assertJsonPath('data.commands.default', './vendor/bin/sail artisan ops:release-check')
            ->assertJsonPath('data.latest.id', $record->id)
            ->assertJsonPath('data.latest.status', 'warn')
            // 概览不应包含逐项 checks 明细（明细需走详情接口）。
            ->assertJsonMissingPath('data.latest.checks');
    }

    /**
     * 验证：调用运行接口会真实执行自检、返回完整结构（含 summary 与 checks），
     * 并把结果落库到 ops_release_checks，同时写入一条成功的审计日志。
     */
    public function test_running_release_check_persists_history_and_writes_audit(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.release.view']);

        $this->postJson('/api/ops/release-check/run')
            ->assertOk()
            ->assertJsonPath('data.status', fn (string $status): bool => in_array($status, ['pass', 'warn', 'fail'], true))
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'status',
                    'summary' => ['pass', 'warn', 'fail'],
                    'checks',
                    'duration_ms',
                    'started_at',
                    'finished_at',
                ],
            ]);

        // 运行结果应落库为该管理员的一条历史。
        $this->assertDatabaseHas('ops_release_checks', [
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
        ]);
        // 并写入审计日志：module=ops.release、action=run、结果 success、状态码 200。
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'ops.release',
            'action' => 'run',
            'result' => 'success',
            'status_code' => 200,
        ]);
    }

    /**
     * 验证：历史列表不返回逐项明细；详情接口返回明细但对敏感值脱敏，
     * token 显示为 [FILTERED]，且响应中不出现任何原始密钥/密码等敏感串。
     */
    public function test_release_check_history_and_detail_return_sanitized_records(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.release.view']);
        // 预置一条 fail 记录，其中 token 已被过滤、error_message 含敏感字样，用于验证脱敏。
        $record = OpsReleaseCheck::query()->create([
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
            'status' => 'fail',
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
            'checks' => [
                [
                    'group' => '环境',
                    'name' => 'Token Check',
                    'status' => 'fail',
                    'message' => 'token value was removed',
                    'hint' => 'token hint was removed',
                    'token' => '[FILTERED]',
                ],
            ],
            'duration_ms' => 20,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'error_message' => 'secret value was filtered',
        ]);

        // 列表项不含 checks 明细。
        $this->getJson('/api/ops/release-check/history')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $record->id)
            ->assertJsonMissingPath('data.items.0.checks');

        // 详情返回明细，token 字段应保持 [FILTERED] 脱敏值。
        $detail = $this->getJson("/api/ops/release-check/history/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.checks.0.token', '[FILTERED]')
            ->json('data');

        // 整个详情 JSON 中都不应泄漏原始敏感串。
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret value', $json);
        $this->assertStringNotContainsString('unsafe-token', $json);
        $this->assertStringNotContainsString('plain-password', $json);
    }
}
