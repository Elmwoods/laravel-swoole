<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Ops\SupervisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 第五阶段后台登录、RBAC 与审计闭环接口测试。
 */
class PhaseFiveSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_ops_api_is_rejected(): void
    {
        $this->getJson('/api/ops/dashboard')
            ->assertStatus(401);
    }

    public function test_admin_can_login_read_profile_and_logout(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.admin.email', $admin->email)
            ->assertJsonPath('data.permissions.0', 'ops.dashboard.view');

        $this->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.admin.email', $admin->email);

        $this->postJson('/api/admin/auth/logout')
            ->assertOk();

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_disabled_admin_cannot_login(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view'], ['is_active' => false]);

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', '后台账号已被禁用。');

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.auth',
            'action' => 'login',
            'result' => 'failure',
            'message' => 'disabled',
        ]);
    }

    public function test_failed_admin_login_is_throttled_after_five_attempts(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/admin/auth/login', [
                'email' => Str::upper($admin->email),
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/admin/auth/login', [
            'email' => Str::upper($admin->email),
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 429);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_email' => Str::upper($admin->email),
            'module' => 'admin.auth',
            'action' => 'login_locked',
            'result' => 'failure',
            'status_code' => 429,
        ]);
    }

    public function test_successful_admin_login_clears_failed_attempts(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/admin/auth/login', [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk();

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_missing_permission_returns_forbidden(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($admin, 'admin')
            ->getJson('/api/ops/logs/laravel')
            ->assertStatus(403);
    }

    public function test_supervisor_control_requires_permission_and_is_audited(): void
    {
        $admin = $this->createAdmin(['ops.supervisor.control']);

        $this->mock(SupervisorService::class, function ($mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with('octane')
                ->andReturn([
                    'service' => 'octane',
                    'result' => 'octane: started',
                ]);
        });

        $this->actingAs($admin, 'admin')
            ->postJson('/api/ops/supervisor/start/octane')
            ->assertOk()
            ->assertJsonPath('data.service', 'octane');

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'ops.supervisor',
            'action' => 'start',
            'result' => 'success',
        ]);
    }

    public function test_admin_management_creates_admin_and_writes_sanitized_audit(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $role = AdminRole::query()->create([
            'name' => '日志管理员',
            'slug' => 'log-admin',
            'description' => '日志查看',
            'is_active' => true,
            'is_system' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/users', [
                'name' => 'New Admin',
                'email' => 'new-admin@example.com',
                'password' => 'created-password',
                'is_active' => true,
                'role_ids' => [$role->id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'new-admin@example.com')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('admin_users', [
            'email' => 'new-admin@example.com',
            'is_active' => true,
        ]);

        $log = AdminAuditLog::query()
            ->where('module', 'admin.users')
            ->where('action', 'create')
            ->firstOrFail();

        $this->assertSame('[FILTERED]', $log->payload['password']);
    }

    public function test_role_management_assigns_permissions_from_whitelist(): void
    {
        $admin = $this->createAdmin(['admin.roles.manage']);
        $permission = AdminPermission::query()->firstOrCreate(
            ['slug' => 'ops.logs.view'],
            ['name' => '查看日志', 'group' => 'ops', 'description' => '查看日志中心'],
        );

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/roles', [
                'name' => '日志查看员',
                'slug' => 'log-viewer',
                'description' => '只能看日志',
                'is_active' => true,
                'permission_ids' => [$permission->id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'log-viewer')
            ->assertJsonPath('data.permissions.0.slug', 'ops.logs.view');
    }

    public function test_last_super_admin_cannot_be_disabled(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $superRole = AdminRole::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => '超级管理员', 'is_active' => true, 'is_system' => true],
        );
        $admin->roles()->syncWithoutDetaching([$superRole->id]);

        $this->actingAs($admin, 'admin')
            ->putJson("/api/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => false,
                'role_ids' => [$superRole->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '不能禁用最后一个超级管理员。');
    }

    public function test_super_admin_can_reset_admin_password_and_invalidate_old_session(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($superAdmin, 'admin')
            ->postJson("/api/admin/users/{$target->id}/reset-password", [
                'password' => 'new-secret-password',
                'password_confirmation' => 'new-secret-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonMissingPath('data.password');

        $target->refresh();

        $this->assertTrue(Hash::check('new-secret-password', $target->password));
        $this->assertSame(2, $target->session_version);
        $this->assertNotNull($target->password_changed_at);

        $this->actingAs($target, 'admin')
            ->withSession(['admin_session_version' => 1])
            ->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', '登录状态已失效，请重新登录后台。');

        $log = AdminAuditLog::query()
            ->where('module', 'admin.users')
            ->where('action', 'reset_password')
            ->firstOrFail();

        $this->assertSame('[FILTERED]', $log->payload['password']);
        $this->assertSame('[FILTERED]', $log->payload['password_confirmation']);
    }

    public function test_non_super_admin_cannot_reset_password_even_with_user_manage_permission(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $target = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($admin, 'admin')
            ->postJson("/api/admin/users/{$target->id}/reset-password", [
                'password' => 'new-secret-password',
                'password_confirmation' => 'new-secret-password',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', '只有超级管理员可以重置密码。');

        $this->assertFalse(Hash::check('new-secret-password', $target->refresh()->password));
    }

    private function createAdmin(array $permissions, array $overrides = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'name' => 'Ops Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ], $overrides));

        $role = AdminRole::query()->create([
            'name' => '测试角色',
            'slug' => 'test-role-'.uniqid(),
            'description' => '测试角色',
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

        return $admin;
    }

    private function createSuperAdmin(): AdminUser
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $superRole = AdminRole::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => '超级管理员', 'is_active' => true, 'is_system' => true],
        );
        $admin->roles()->syncWithoutDetaching([$superRole->id]);

        return $admin->refresh();
    }
}
