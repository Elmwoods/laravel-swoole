<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Services\Admin\AdminPermissionRegistry;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Ops\Docker\DockerService;
use App\Services\Ops\OctaneControlService;
use App\Services\Ops\SupervisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
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

        $this->withHeader('User-Agent', 'Ops Browser/1.0 token=should-not-appear')
            ->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.admin.email', $admin->email)
            ->assertJsonPath('data.permissions.0', 'ops.dashboard.view');

        $this->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.admin.email', $admin->email)
            ->assertJsonPath('data.security.last_login_ip', '127.0.0.1')
            ->assertJsonPath('data.security.session_version', 1)
            ->assertJsonMissingPath('data.security.cookie')
            ->assertJsonMissingPath('data.security.token')
            ->assertJsonMissingPath('data.security.password_encrypted');

        $this->assertSame('127.0.0.1', $admin->refresh()->last_login_ip);
        $this->assertStringContainsString('Ops Browser/1.0', (string) $admin->last_login_user_agent);
        $securityJson = json_encode($this->getJson('/api/admin/auth/me')->json('data.security'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', $securityJson);
        $this->assertStringNotContainsString('token=should-not-appear', $securityJson);

        $this->postJson('/api/admin/auth/logout')
            ->assertOk();

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401);
    }

    public function test_admin_login_stores_session_version_and_last_activity_timestamp(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertSessionHas('admin_session_version', (int) $admin->session_version)
            ->assertSessionHas('admin_last_activity_at');

        $this->assertIsInt(session('admin_last_activity_at'));
    }

    public function test_admin_request_refreshes_last_activity_before_idle_timeout(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $staleActivity = now()->subMinutes(119)->timestamp;

        $this->actingAs($admin, 'admin')
            ->withSession([
                'admin_session_version' => (int) $admin->session_version,
                'admin_last_activity_at' => $staleActivity,
            ])
            ->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertSessionHas('admin_last_activity_at');

        $this->assertGreaterThan($staleActivity, (int) session('admin_last_activity_at'));
    }

    public function test_idle_admin_session_expires_and_clears_login_state(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($admin, 'admin')
            ->withSession([
                'admin_session_version' => (int) $admin->session_version,
                'admin_last_activity_at' => now()->subMinutes(121)->timestamp,
            ])
            ->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', '登录已超时，请重新登录后台。');
    }

    public function test_session_version_mismatch_takes_priority_over_idle_timeout(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view'], ['session_version' => 2]);

        $this->actingAs($admin, 'admin')
            ->withSession([
                'admin_session_version' => 1,
                'admin_last_activity_at' => now()->subMinutes(121)->timestamp,
            ])
            ->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', '登录状态已失效，请重新登录后台。');
    }

    public function test_admin_password_key_endpoint_does_not_return_private_key(): void
    {
        $this->getJson('/api/admin/auth/password-key')
            ->assertOk()
            ->assertJsonPath('data.algorithm', 'RSA-OAEP-SHA1')
            ->assertJsonStructure(['data' => ['key_id', 'algorithm', 'public_key']])
            ->assertJsonMissingPath('data.private_key');
    }

    public function test_plaintext_admin_login_password_is_rejected(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->postJson('/api/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password_encrypted', 'password_key_id']);
    }

    public function test_disabled_admin_cannot_login(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view'], ['is_active' => false]);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))
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
            $this->postJson('/api/admin/auth/login', array_merge([
                'email' => Str::upper($admin->email),
            ], $this->encryptedPasswordPayload('wrong-password')))->assertStatus(422);
        }

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => Str::upper($admin->email),
        ], $this->encryptedPasswordPayload('wrong-password')))
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

    public function test_locked_admin_login_does_not_decrypt_password_payload(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $email = Str::upper($admin->email);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            app(\App\Services\Admin\AdminLoginThrottleService::class)->hit($email, '127.0.0.1');
        }

        $this->mock(AdminPasswordCryptoService::class, function ($mock): void {
            $mock->shouldNotReceive('decryptPasswordFromPayload');
        });

        $this->postJson('/api/admin/auth/login', [
            'email' => $email,
            'password_encrypted' => 'ciphertext-not-needed-when-locked',
            'password_key_id' => '1234567890abcdef',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 429);
    }

    public function test_successful_admin_login_clears_failed_attempts(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('wrong-password')))->assertStatus(422);
        }

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('wrong-password')))->assertStatus(422);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('wrong-password')))->assertStatus(422);
    }

    public function test_missing_permission_returns_forbidden(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($admin, 'admin')
            ->getJson('/api/ops/logs/laravel')
            ->assertStatus(403);
    }

    public function test_admin_and_ops_routes_reject_authenticated_admins_without_required_permission(): void
    {
        $admin = $this->createAdmin([]);
        $targetAdmin = $this->createAdmin(['ops.dashboard.view']);
        $role = AdminRole::query()->create([
            'name' => '矩阵测试角色',
            'slug' => 'matrix-role',
            'description' => '矩阵测试角色',
            'is_active' => true,
            'is_system' => false,
        ]);
        $alert = OpsAlert::query()->create([
            'fingerprint' => 'matrix-alert',
            'source' => 'disk',
            'severity' => 'warning',
            'title' => 'Matrix Alert',
            'message' => 'Matrix alert for permission tests.',
            'status' => 'open',
            'last_seen_at' => now(),
        ]);

        foreach ($this->permissionProtectedEndpoints($targetAdmin, $role, $alert) as [$method, $uri, $payload]) {
            $this->actingAs($admin, 'admin')
                ->json($method, $uri, $payload)
                ->assertStatus(403)
                ->assertJsonPath('code', 403);
        }
    }

    public function test_all_route_permission_slugs_are_registered_in_permission_registry(): void
    {
        $routePermissions = collect(Route::getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'admin.permission:'))
            ->map(fn (string $middleware): string => Str::after($middleware, 'admin.permission:'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($routePermissions);
        $this->assertSame([], array_values(array_diff($routePermissions, AdminPermissionRegistry::slugs())));
    }

    public function test_sensitive_ops_control_routes_are_audited(): void
    {
        $missingAudit = collect(Route::getRoutes())
            ->filter(fn ($route): bool => in_array('POST', $route->methods(), true))
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/ops/'))
            ->reject(fn ($route): bool => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'admin.audit:')))
            ->map(fn ($route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $missingAudit);
    }

    public function test_admin_permissions_allow_requests_to_reach_controller_or_validation_layer(): void
    {
        $userManager = $this->createAdmin(['admin.users.manage']);
        $roleManager = $this->createAdmin(['admin.roles.manage']);
        $auditViewer = $this->createAdmin(['admin.audit.view']);

        $this->actingAs($userManager, 'admin')
            ->getJson('/api/admin/users')
            ->assertOk();

        $this->actingAs($userManager, 'admin')
            ->postJson('/api/admin/users', [])
            ->assertStatus(422);

        $this->actingAs($roleManager, 'admin')
            ->getJson('/api/admin/roles')
            ->assertOk();

        $this->actingAs($roleManager, 'admin')
            ->postJson('/api/admin/roles', [])
            ->assertStatus(422);

        $this->actingAs($auditViewer, 'admin')
            ->getJson('/api/admin/audit-logs')
            ->assertOk();
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
            ->postJson('/api/admin/users', array_merge([
                'name' => 'New Admin',
                'email' => 'new-admin@example.com',
                'is_active' => true,
                'role_ids' => [$role->id],
            ], $this->encryptedPasswordPayload('created-password')))
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

        $this->assertArrayNotHasKey('password', $log->payload);
        $this->assertSame('[FILTERED]', $log->payload['password_encrypted']);
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

    public function test_last_super_admin_cannot_have_super_role_removed(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $superRole = AdminRole::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => '超级管理员', 'is_active' => true, 'is_system' => true],
        );
        $normalRole = AdminRole::query()->create([
            'name' => '普通管理员',
            'slug' => 'normal-admin',
            'description' => '普通后台管理员',
            'is_active' => true,
            'is_system' => false,
        ]);
        $admin->roles()->syncWithoutDetaching([$superRole->id]);

        $this->actingAs($admin, 'admin')
            ->putJson("/api/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => true,
                'role_ids' => [$normalRole->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertTrue($admin->refresh()->isSuperAdmin());
    }

    public function test_admin_user_cannot_bind_disabled_role(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $disabledRole = AdminRole::query()->create([
            'name' => '禁用角色',
            'slug' => 'disabled-role',
            'description' => '禁用角色',
            'is_active' => false,
            'is_system' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/users', array_merge([
                'name' => 'Disabled Role Admin',
                'email' => 'disabled-role-admin@example.com',
                'is_active' => true,
                'role_ids' => [$disabledRole->id],
            ], $this->encryptedPasswordPayload('created-password')))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_ids.0']);
    }

    public function test_admin_user_requires_at_least_one_active_role(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/users', array_merge([
                'name' => 'No Role Admin',
                'email' => 'no-role-admin@example.com',
                'is_active' => true,
                'role_ids' => [],
            ], $this->encryptedPasswordPayload('created-password')))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_ids']);
    }

    public function test_super_admin_role_cannot_be_disabled_or_lose_permissions(): void
    {
        $admin = $this->createAdmin(['admin.roles.manage']);
        app(\App\Services\Admin\AdminPermissionRegistry::class)->syncDefaults();
        $superRole = AdminRole::query()->where('slug', 'super_admin')->firstOrFail();
        $permissionIds = AdminPermission::query()
            ->whereIn('slug', \App\Services\Admin\AdminPermissionRegistry::slugs())
            ->pluck('id')
            ->all();

        $this->actingAs($admin, 'admin')
            ->putJson("/api/admin/roles/{$superRole->id}", [
                'name' => '超级管理员',
                'slug' => 'super_admin',
                'description' => '拥有所有后台权限',
                'is_active' => false,
                'permission_ids' => $permissionIds,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->actingAs($admin, 'admin')
            ->putJson("/api/admin/roles/{$superRole->id}", [
                'name' => '超级管理员',
                'slug' => 'super_admin',
                'description' => '拥有所有后台权限',
                'is_active' => true,
                'permission_ids' => array_slice($permissionIds, 0, -1),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permission_ids']);
    }

    public function test_super_admin_can_reset_admin_password_and_invalidate_old_session(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($superAdmin, 'admin')
            ->postJson("/api/admin/users/{$target->id}/reset-password", $this->encryptedPasswordResetPayload('new-secret-password'))
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

        $this->assertArrayNotHasKey('password', $log->payload);
        $this->assertArrayNotHasKey('password_confirmation', $log->payload);
        $this->assertSame('[FILTERED]', $log->payload['password_encrypted']);
        $this->assertSame('[FILTERED]', $log->payload['password_confirmation_encrypted']);
    }

    public function test_super_admin_reset_password_rejects_mismatched_confirmation(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($superAdmin, 'admin')
            ->postJson(
                "/api/admin/users/{$target->id}/reset-password",
                $this->encryptedPasswordResetPayload('new-secret-password', 'different-secret-password'),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password_confirmation_encrypted']);

        $this->assertFalse(Hash::check('new-secret-password', $target->refresh()->password));
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $superAdmin->id,
            'module' => 'admin.users',
            'action' => 'reset_password',
            'result' => 'failure',
            'status_code' => 422,
        ]);
    }

    public function test_non_super_admin_cannot_reset_password_even_with_user_manage_permission(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $target = $this->createAdmin(['ops.dashboard.view']);

        $this->actingAs($admin, 'admin')
            ->postJson("/api/admin/users/{$target->id}/reset-password", $this->encryptedPasswordResetPayload('new-secret-password'))
            ->assertStatus(403)
            ->assertJsonPath('message', '只有超级管理员可以重置密码。');

        $this->assertFalse(Hash::check('new-secret-password', $target->refresh()->password));
    }

    public function test_audit_log_filters_by_actor_module_action_result_and_time(): void
    {
        $viewer = $this->createAdmin(['admin.audit.view']);
        $other = $this->createAdmin(['admin.audit.view']);
        $matched = AdminAuditLog::query()->create([
            'admin_user_id' => $viewer->id,
            'admin_email' => $viewer->email,
            'module' => 'admin.roles',
            'action' => 'update',
            'result' => 'failure',
            'status_code' => 422,
            'payload' => ['role' => 'super_admin'],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        AdminAuditLog::query()->create([
            'admin_user_id' => $other->id,
            'admin_email' => $other->email,
            'module' => 'admin.users',
            'action' => 'create',
            'result' => 'success',
            'status_code' => 201,
            'payload' => [],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->actingAs($viewer, 'admin')
            ->getJson('/api/admin/audit-logs?'.http_build_query([
                'admin_user_id' => $viewer->id,
                'module' => 'admin.roles',
                'action' => 'update',
                'result' => 'failure',
                'from' => now()->subHours(2)->toDateTimeString(),
                'to' => now()->toDateTimeString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $matched->id)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_audit_log_export_requires_login_and_permission(): void
    {
        $this->getJson('/api/admin/audit-logs/export')
            ->assertStatus(401);

        $admin = $this->createAdmin([]);

        $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/audit-logs/export')
            ->assertStatus(403);
    }

    public function test_audit_log_export_filters_and_sanitizes_csv_fields(): void
    {
        $viewer = $this->createAdmin(['admin.audit.view']);
        $matched = $this->createAuditLog([
            'admin_user_id' => $viewer->id,
            'admin_email' => '=admin@example.com',
            'module' => 'admin.users',
            'action' => 'reset_password',
            'result' => 'success',
            'status_code' => 200,
            'target_type' => 'admin_user',
            'target_id' => '+42',
            'payload' => [
                'email' => 'target@example.com',
                'password' => 'plain-secret',
                'token' => 'unsafe-token',
                'nested' => ['cookie' => 'unsafe-cookie'],
            ],
            'ip_address' => '127.0.0.1',
        ], now()->subMinutes(10));
        $this->createAuditLog([
            'module' => 'ops.logs',
            'action' => 'download',
            'result' => 'failure',
            'status_code' => 422,
        ], now()->subMinutes(5));

        $response = $this->actingAs($viewer, 'admin')
            ->get('/api/admin/audit-logs/export?'.http_build_query([
                'module' => 'admin.users',
                'action' => 'reset_password',
                'result' => 'success',
                'status_code' => 200,
            ]));

        $response->assertOk()
            ->assertHeader('content-disposition');

        $content = $response->streamedContent();

        $this->assertStringContainsString('id,admin_user_id,admin_email,module,action,result,status_code,target,ip_address,created_at,payload_summary', $content);
        $this->assertStringContainsString((string) $matched->id, $content);
        $this->assertStringContainsString("'=admin@example.com", $content);
        $this->assertStringContainsString("admin_user:'+42", $content);
        $this->assertStringContainsString('[FILTERED]', $content);
        $this->assertStringNotContainsString('plain-secret', $content);
        $this->assertStringNotContainsString('unsafe-token', $content);
        $this->assertStringNotContainsString('unsafe-cookie', $content);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $viewer->id,
            'module' => 'admin.audit',
            'action' => 'export',
            'result' => 'success',
            'status_code' => 200,
        ]);
    }

    public function test_audit_prune_dry_run_reports_count_without_deleting_logs(): void
    {
        $oldLog = $this->createAuditLog(['module' => 'admin.users'], now()->subDays(200));
        $recentLog = $this->createAuditLog(['module' => 'admin.roles'], now()->subDays(10));

        $this->artisan('admin:audit-prune', [
            '--days' => 180,
            '--dry-run' => true,
        ])
            ->expectsOutput('将删除 1 条 180 天以前的审计日志。')
            ->assertExitCode(0);

        $this->assertDatabaseHas('admin_audit_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $recentLog->id]);
    }

    public function test_audit_prune_deletes_logs_older_than_retention_only(): void
    {
        $oldLog = $this->createAuditLog(['module' => 'admin.users'], now()->subDays(181));
        $recentLog = $this->createAuditLog(['module' => 'admin.roles'], now()->subDays(179));

        $this->artisan('admin:audit-prune', ['--days' => 180])
            ->expectsOutput('已删除 1 条 180 天以前的审计日志。')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('admin_audit_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['id' => $recentLog->id]);
    }

    public function test_audit_prune_rejects_invalid_retention_days_without_deleting(): void
    {
        $oldLog = $this->createAuditLog(['module' => 'admin.users'], now()->subDays(200));

        $this->artisan('admin:audit-prune', ['--days' => 29])
            ->expectsOutput('审计日志保留天数必须在 30 到 3650 天之间。')
            ->assertExitCode(1);

        $this->assertDatabaseHas('admin_audit_logs', ['id' => $oldLog->id]);
    }

    public function test_non_super_admin_reset_password_does_not_decrypt_password_payload(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $target = $this->createAdmin(['ops.dashboard.view']);

        $this->mock(AdminPasswordCryptoService::class, function ($mock): void {
            $mock->shouldNotReceive('decryptPasswordFromPayload');
            $mock->shouldNotReceive('decryptConfirmedPasswordFromPayload');
        });

        $this->actingAs($admin, 'admin')
            ->postJson("/api/admin/users/{$target->id}/reset-password", [
                'password_encrypted' => 'ciphertext-not-needed-when-forbidden',
                'password_confirmation_encrypted' => 'ciphertext-not-needed-when-forbidden',
                'password_key_id' => '1234567890abcdef',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', '只有超级管理员可以重置密码。');
    }

    public function test_docker_stop_requires_confirm_text_and_does_not_call_service_when_missing(): void
    {
        $admin = $this->createAdmin(['ops.docker.control']);

        $this->mock(DockerService::class, function ($mock): void {
            $mock->shouldNotReceive('stop');
        });

        $this->actingAs($admin, 'admin')
            ->postJson('/api/ops/docker/stop/container-id')
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_text');

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'ops.docker',
            'action' => 'stop',
            'result' => 'failure',
            'status_code' => 422,
        ]);
    }

    public function test_docker_restart_accepts_confirm_text_and_calls_service(): void
    {
        $admin = $this->createAdmin(['ops.docker.control']);

        $this->mock(DockerService::class, function ($mock): void {
            $mock->shouldReceive('restart')
                ->once()
                ->with('container-id')
                ->andReturn(['status' => 'restarted']);
        });

        $this->actingAs($admin, 'admin')
            ->postJson('/api/ops/docker/restart/container-id', ['confirm_text' => 'CONFIRM'])
            ->assertOk()
            ->assertJsonPath('data.status', 'restarted');
    }

    public function test_supervisor_and_octane_high_risk_actions_require_confirm_text(): void
    {
        $admin = $this->createAdmin(['ops.supervisor.control', 'ops.system.view']);

        $this->mock(SupervisorService::class, function ($mock): void {
            $mock->shouldNotReceive('stop');
        });
        $this->mock(OctaneControlService::class, function ($mock): void {
            $mock->shouldNotReceive('reload');
        });

        $this->actingAs($admin, 'admin')
            ->postJson('/api/ops/supervisor/stop/octane')
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_text');

        $this->actingAs($admin, 'admin')
            ->postJson('/api/ops/octane/reload', ['confirm_text' => 'WRONG'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_text');
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

        return $admin->refresh();
    }

    private function encryptedPasswordPayload(string $password): array
    {
        return [
            'password_encrypted' => $this->encryptPassword($password),
            'password_key_id' => app(AdminPasswordCryptoService::class)->publicKeyPayload()['key_id'],
        ];
    }

    private function encryptedPasswordResetPayload(string $password, ?string $confirmation = null): array
    {
        return [
            'password_encrypted' => $this->encryptPassword($password),
            'password_confirmation_encrypted' => $this->encryptPassword($confirmation ?? $password),
            'password_key_id' => app(AdminPasswordCryptoService::class)->publicKeyPayload()['key_id'],
        ];
    }

    private function encryptPassword(string $password): string
    {
        $encrypted = '';
        $ok = openssl_public_encrypt(
            $password,
            $encrypted,
            app(AdminPasswordCryptoService::class)->publicKey(),
            OPENSSL_PKCS1_OAEP_PADDING,
        );

        $this->assertTrue($ok);

        return base64_encode($encrypted);
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

    private function createAuditLog(array $overrides, \DateTimeInterface $createdAt): AdminAuditLog
    {
        $log = AdminAuditLog::query()->create(array_merge([
            'admin_user_id' => null,
            'admin_email' => null,
            'module' => 'admin.audit',
            'action' => 'test',
            'result' => 'success',
            'status_code' => 200,
            'payload' => [],
            'ip_address' => '127.0.0.1',
        ], $overrides));

        $log->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $log->refresh();
    }

    private function permissionProtectedEndpoints(AdminUser $targetAdmin, AdminRole $role, OpsAlert $alert): array
    {
        return [
            ['GET', '/api/admin/users', []],
            ['POST', '/api/admin/users', []],
            ['PUT', "/api/admin/users/{$targetAdmin->id}", []],
            ['POST', "/api/admin/users/{$targetAdmin->id}/reset-password", []],
            ['GET', '/api/admin/roles', []],
            ['POST', '/api/admin/roles', []],
            ['PUT', "/api/admin/roles/{$role->id}", []],
            ['GET', '/api/admin/audit-logs', []],
            ['GET', '/api/ops/dashboard', []],
            ['GET', '/api/ops/octane/status', []],
            ['POST', '/api/ops/octane/reload', []],
            ['GET', '/api/ops/redis/info', []],
            ['GET', '/api/ops/redis-metrics/push', []],
            ['GET', '/api/ops/queue/summary', []],
            ['GET', '/api/ops/supervisor/status', []],
            ['POST', '/api/ops/supervisor/start/octane', []],
            ['GET', '/api/ops/docker/summary', []],
            ['POST', '/api/ops/docker/restart/container-id', []],
            ['GET', '/api/ops/system/summary', []],
            ['GET', '/api/ops/network', []],
            ['GET', '/api/ops/alerts', []],
            ['GET', '/api/ops/alerts/evaluations/latest', []],
            ['GET', '/api/ops/alerts/settings', []],
            ['PUT', '/api/ops/alerts/settings', []],
            ['GET', '/api/ops/alerts/rules', []],
            ['POST', '/api/ops/alerts/evaluate', []],
            ['POST', "/api/ops/alerts/{$alert->id}/acknowledge", []],
            ['POST', "/api/ops/alerts/{$alert->id}/assign", []],
            ['GET', '/api/ops/logs/laravel', []],
            ['GET', '/api/ops/test-broadcast', []],
        ];
    }
}
