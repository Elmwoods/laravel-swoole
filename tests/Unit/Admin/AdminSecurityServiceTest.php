<?php

namespace Tests\Unit\Admin;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
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
}
