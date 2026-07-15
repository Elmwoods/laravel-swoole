<?php

namespace Tests\Unit\Admin;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminLoginThrottleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 后台权限判定与审计脱敏单元测试。
 */
class AdminSecurityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_permissions_merge_active_roles_only(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $activeRole = AdminRole::query()->create([
            'name' => 'Active',
            'slug' => 'active',
            'is_active' => true,
        ]);
        $disabledRole = AdminRole::query()->create([
            'name' => 'Disabled',
            'slug' => 'disabled',
            'is_active' => false,
        ]);
        $view = AdminPermission::query()->create([
            'name' => 'Dashboard',
            'slug' => 'ops.dashboard.view',
            'group' => 'ops',
        ]);
        $control = AdminPermission::query()->create([
            'name' => 'Docker',
            'slug' => 'ops.docker.control',
            'group' => 'ops',
        ]);

        $activeRole->permissions()->attach($view->id);
        $disabledRole->permissions()->attach($control->id);
        $admin->roles()->attach([$activeRole->id, $disabledRole->id]);

        $this->assertTrue($admin->hasPermission('ops.dashboard.view'));
        $this->assertFalse($admin->hasPermission('ops.docker.control'));
        $this->assertSame(['ops.dashboard.view'], $admin->permissionSlugs());
    }

    public function test_disabled_admin_has_no_permissions(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $this->assertFalse($admin->hasPermission('ops.dashboard.view'));
        $this->assertSame([], $admin->permissionSlugs());
    }

    public function test_audit_payload_filters_sensitive_values(): void
    {
        $payload = app(AdminAuditService::class)->sanitizePayload([
            'email' => 'admin@example.com',
            'password' => 'secret',
            'token' => 'unsafe-token',
            'nested' => [
                'bot_token' => 'telegram-token',
                'chat_id' => '123456',
            ],
        ]);

        $this->assertSame('admin@example.com', $payload['email']);
        $this->assertSame('[FILTERED]', $payload['password']);
        $this->assertSame('[FILTERED]', $payload['token']);
        $this->assertSame('[FILTERED]', $payload['nested']['bot_token']);
        $this->assertSame('[FILTERED]', $payload['nested']['chat_id']);
    }

    public function test_login_throttle_key_normalizes_email_and_includes_ip(): void
    {
        $service = app(AdminLoginThrottleService::class);

        $this->assertSame(
            $service->key('ADMIN@example.com', '127.0.0.1'),
            $service->key('admin@example.com', '127.0.0.1'),
        );
        $this->assertNotSame(
            $service->key('admin@example.com', '127.0.0.1'),
            $service->key('admin@example.com', '127.0.0.2'),
        );
    }

    public function test_session_version_must_match_current_admin_version(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'session_version' => 3,
        ]);

        $this->assertTrue($admin->sessionVersionMatches(3));
        $this->assertFalse($admin->sessionVersionMatches(2));
        $this->assertFalse($admin->sessionVersionMatches(null));
    }

    public function test_is_super_admin_requires_active_user_and_active_super_role(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $superRole = AdminRole::query()->create([
            'name' => 'Super',
            'slug' => 'super_admin',
            'is_active' => true,
        ]);
        $admin->roles()->attach($superRole->id);

        $this->assertTrue($admin->isSuperAdmin());

        $superRole->forceFill(['is_active' => false])->save();
        $this->assertFalse($admin->refresh()->isSuperAdmin());

        $superRole->forceFill(['is_active' => true])->save();
        $admin->forceFill(['is_active' => false])->save();
        $this->assertFalse($admin->refresh()->isSuperAdmin());
    }
}
