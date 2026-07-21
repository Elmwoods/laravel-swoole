<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OpsInspection;
use App\Services\Admin\AdminPermissionRegistry;
use App\Services\Ops\OpsInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseTwelveInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_summary_requires_authentication(): void
    {
        $this->getJson('/api/ops/inspections/summary')
            ->assertStatus(401);
    }

    public function test_inspection_routes_require_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);

        $this->getJson('/api/ops/inspections/summary')
            ->assertStatus(403);

        $this->postJson('/api/ops/inspections/run')
            ->assertStatus(403);
    }

    public function test_inspection_permission_is_registered_for_ops_admin_only(): void
    {
        app(AdminPermissionRegistry::class)->syncDefaults();

        $this->assertDatabaseHas('admin_permissions', [
            'slug' => 'ops.inspections.view',
            'name' => '自动巡检查看',
            'group' => 'ops',
        ]);

        $this->assertTrue(AdminRole::query()
            ->where('slug', 'ops_admin')
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'ops.inspections.view'))
            ->exists());
        $this->assertFalse(AdminRole::query()
            ->where('slug', 'audit_viewer')
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'ops.inspections.view'))
            ->exists());
    }

    public function test_inspection_summary_and_history_return_latest_records(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.inspections.view']);
        $old = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'pass',
            'summary' => ['pass' => 2, 'warn' => 0, 'fail' => 0],
            'checks' => [['group' => '队列/调度', 'name' => 'Queue Workers', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']],
            'duration_ms' => 8,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);
        $latest = OpsInspection::query()->create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'warn',
            'summary' => ['pass' => 2, 'warn' => 1, 'fail' => 0],
            'checks' => [['group' => '发布自检', 'name' => 'APP_DEBUG', 'status' => 'warn', 'message' => 'debug on', 'hint' => 'turn off']],
            'duration_ms' => 12,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
        ]);

        $this->getJson('/api/ops/inspections/summary')
            ->assertOk()
            ->assertJsonPath('data.latest.id', $latest->id)
            ->assertJsonPath('data.summary.warn', 1)
            ->assertJsonMissingPath('data.latest.checks');

        $this->getJson('/api/ops/inspections/history')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $latest->id)
            ->assertJsonPath('data.items.1.id', $old->id)
            ->assertJsonMissingPath('data.items.0.checks');

        $this->getJson("/api/ops/inspections/history/{$latest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $latest->id)
            ->assertJsonPath('data.checks.0.group', '发布自检');
    }

    public function test_manual_inspection_run_persists_history_and_writes_audit(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.inspections.view']);

        $this->mock(OpsInspectionService::class, function ($mock) use ($admin): void {
            $record = OpsInspection::query()->create([
                'type' => 'full',
                'trigger' => 'manual',
                'status' => 'pass',
                'summary' => ['pass' => 4, 'warn' => 0, 'fail' => 0],
                'checks' => [['group' => '巡检', 'name' => 'Mock', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']],
                'duration_ms' => 15,
                'started_at' => now()->subSecond(),
                'finished_at' => now(),
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
            ]);

            $mock->shouldReceive('run')
                ->once()
                ->with('full', 'manual', $admin)
                ->andReturn($record);
            $mock->shouldReceive('serializeDetail')
                ->once()
                ->with($record)
                ->andReturn([
                    'id' => $record->id,
                    'type' => 'full',
                    'trigger' => 'manual',
                    'status' => 'pass',
                    'summary' => ['pass' => 4, 'warn' => 0, 'fail' => 0],
                    'checks' => $record->checks,
                    'duration_ms' => 15,
                    'started_at' => optional($record->started_at)->toDateTimeString(),
                    'finished_at' => optional($record->finished_at)->toDateTimeString(),
                    'admin_email' => $admin->email,
                    'failure_message' => null,
                    'created_at' => optional($record->created_at)->toDateTimeString(),
                ]);
        });

        $this->postJson('/api/ops/inspections/run')
            ->assertOk()
            ->assertJsonPath('data.status', 'pass')
            ->assertJsonPath('data.summary.pass', 4);

        $this->assertDatabaseHas('ops_inspections', [
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'pass',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'ops.inspections',
            'action' => 'run',
            'result' => 'success',
            'status_code' => 200,
        ]);
    }

    public function test_inspection_failure_detail_is_sanitized(): void
    {
        $this->actingAsAdminWithPermissions(['ops.inspections.view']);
        $record = OpsInspection::query()->create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'fail',
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
            'checks' => [[
                'group' => '发布自检',
                'name' => 'Secret Check',
                'status' => 'fail',
                'message' => 'token=unsafe-token failed',
                'hint' => 'password=plain-password leaked',
                'secret' => 'unsafe-secret',
            ]],
            'failure_message' => 'authorization=unsafe failed',
            'duration_ms' => 20,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $detail = $this->getJson("/api/ops/inspections/history/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.checks.0.secret', '[FILTERED]')
            ->json('data');

        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('unsafe-token', $json);
        $this->assertStringNotContainsString('plain-password', $json);
        $this->assertStringNotContainsString('unsafe-secret', $json);
    }

    protected function actingAsAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'Inspection Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
        $role = AdminRole::query()->create([
            'name' => 'Inspection Role',
            'slug' => 'inspection-role-'.uniqid(),
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $slug) {
            $permission = AdminPermission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => explode('.', $slug)[0], 'description' => $slug],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->attach($role->id);
        $this->actingAs($admin, 'admin');

        return $admin->refresh();
    }
}
