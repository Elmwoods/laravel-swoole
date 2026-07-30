<?php

namespace Tests\Feature\Ops;

use App\Models\AdminRole;
use App\Models\OpsReleaseCheck;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTenReleaseCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_check_overview_requires_authentication(): void
    {
        $this->getJson('/api/ops/release-check/overview')
            ->assertStatus(401);
    }

    public function test_release_check_routes_require_release_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);

        $this->getJson('/api/ops/release-check/overview')
            ->assertStatus(403);

        $this->postJson('/api/ops/release-check/run')
            ->assertStatus(403);
    }

    public function test_release_check_permission_is_registered_and_assigned_to_ops_admin_only(): void
    {
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

    public function test_release_check_overview_returns_latest_summary_without_running_full_check(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.release.view']);
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
            ->assertJsonMissingPath('data.latest.checks');
    }

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

        $this->assertDatabaseHas('ops_release_checks', [
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'ops.release',
            'action' => 'run',
            'result' => 'success',
            'status_code' => 200,
        ]);
    }

    public function test_release_check_history_and_detail_return_sanitized_records(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.release.view']);
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

        $this->getJson('/api/ops/release-check/history')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $record->id)
            ->assertJsonMissingPath('data.items.0.checks');

        $detail = $this->getJson("/api/ops/release-check/history/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.checks.0.token', '[FILTERED]')
            ->json('data');

        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret value', $json);
        $this->assertStringNotContainsString('unsafe-token', $json);
        $this->assertStringNotContainsString('plain-password', $json);
    }
}
