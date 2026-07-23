<?php

namespace Tests\Feature\Ops;

use App\Models\AdminIpRule;
use App\Models\AdminSecuritySetting;
use App\Services\Admin\AdminIpAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTwentyFourIpAccessTest extends TestCase
{
    use RefreshDatabase;

    private function enableBlocklist(): AdminIpAccessService
    {
        $svc = app(AdminIpAccessService::class);
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);

        return $svc;
    }

    private function loginPayload(string $email = 'someone@example.com'): array
    {
        return [
            'email' => $email,
            'password_encrypted' => 'ignored-ciphertext',
            'password_key_id' => 'xxxxxxxxxxxxxxxx',
        ];
    }

    public function test_login_denied_from_blocklisted_ip(): void
    {
        $svc = $this->enableBlocklist();
        $svc->createRule('deny', '203.0.113.0/24', '封禁网段');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/admin/auth/login', $this->loginPayload())
            ->assertStatus(403)
            ->assertJsonPath('message', '当前网络环境不允许访问后台。');

        $this->assertGuest('admin');
        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'admin.auth',
            'action' => 'login_denied',
            'result' => 'failure',
        ]);
    }

    public function test_login_allowed_ip_passes_the_gate(): void
    {
        $svc = $this->enableBlocklist();
        $svc->createRule('deny', '203.0.113.0/24', '封禁网段');

        // 未被封禁的 IP 通过准入门 → 继续走密码解密（bogus 密文 → 422 校验错，而非 403）。
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->postJson('/api/admin/auth/login', $this->loginPayload())
            ->assertStatus(422);

        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'login_denied',
        ]);
    }

    public function test_middleware_kills_active_session_when_ip_becomes_denied(): void
    {
        $svc = $this->enableBlocklist();
        $svc->createRule('deny', '203.0.113.0/24', '其它网段');

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
        $this->actingAsAdminWithPermissions([]);

        // 当前 IP 未被封禁 → 正常访问。
        $this->getJson('/api/admin/auth/me')->assertOk();

        // 把当前 IP 加入黑名单 → 下次请求即被踢。
        $svc->createRule('deny', '10.1.2.3/32', '封禁自己');

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', '您的 IP 不在允许访问后台的范围。');
    }

    public function test_allowlist_mode_only_permits_listed_sessions(): void
    {
        $svc = app(AdminIpAccessService::class);
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'allowlist']);
        $svc->createRule('allow', '10.0.0.0/8', '办公网');

        // 白名单内。
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9']);
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/admin/auth/me')->assertOk();

        // 白名单外。
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/admin/auth/me')->assertStatus(401);
    }

    public function test_disabled_leaves_login_and_sessions_unaffected(): void
    {
        // 默认关闭：即使有 deny 规则也不生效。
        app(AdminIpAccessService::class)->createRule('deny', '10.0.0.0/8', null);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1']);
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/admin/auth/me')->assertOk();
    }

    public function test_crud_endpoints_require_security_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/admin/ip-rules')->assertStatus(403);
        $this->postJson('/api/admin/ip-rules', ['type' => 'deny', 'cidr' => '10.0.0.0/8'])->assertStatus(403);
    }

    public function test_manage_ip_rules_end_to_end(): void
    {
        $this->actingAsAdminWithPermissions(['admin.security.manage']);

        $this->getJson('/api/admin/ip-rules')
            ->assertOk()
            ->assertJsonPath('data.settings.ip_access_enabled', false)
            ->assertJsonPath('data.settings.ip_access_mode', 'blocklist');

        $ruleId = $this->postJson('/api/admin/ip-rules', ['type' => 'deny', 'cidr' => '203.0.113.0/24', 'label' => '封禁'])
            ->assertOk()
            ->assertJsonPath('data.rule.type', 'deny')
            ->json('data.rule.id');

        $this->assertDatabaseHas('admin_ip_rules', ['id' => $ruleId, 'cidr' => '203.0.113.0/24', 'is_active' => true]);

        // 停用。
        $this->patchJson("/api/admin/ip-rules/{$ruleId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.updated', true);
        $this->assertDatabaseHas('admin_ip_rules', ['id' => $ruleId, 'is_active' => false]);

        // 切换设置。
        $this->putJson('/api/admin/ip-access/settings', ['ip_access_enabled' => true, 'ip_access_mode' => 'allowlist'])
            ->assertOk()
            ->assertJsonPath('data.settings.ip_access_mode', 'allowlist');

        // 删除。
        $this->deleteJson("/api/admin/ip-rules/{$ruleId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('admin_ip_rules', ['id' => $ruleId]);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'admin.security', 'action' => 'ip_rule_create']);
    }

    public function test_invalid_cidr_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['admin.security.manage']);

        $this->postJson('/api/admin/ip-rules', ['type' => 'deny', 'cidr' => 'not-a-cidr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cidr']);
    }

    public function test_break_glass_command_disables_and_flushes(): void
    {
        $svc = app(AdminIpAccessService::class);
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);
        $svc->createRule('deny', '203.0.113.0/24', null);

        $this->assertFalse($svc->allowedFor('203.0.113.9'));

        $this->artisan('admin:ip-access --disable')->assertExitCode(0);
        $this->assertFalse((bool) AdminSecuritySetting::value('ip_access_enabled'));
        // 关闭后放行。
        $this->assertTrue(app(AdminIpAccessService::class)->allowedFor('203.0.113.9'));

        $this->artisan('admin:ip-access --flush')->assertExitCode(0);
        $this->assertSame(0, AdminIpRule::query()->count());

        $this->artisan('admin:ip-access --status')->assertExitCode(0);
    }

    public function test_break_glass_command_switches_mode(): void
    {
        $this->artisan('admin:ip-access --allowlist')->assertExitCode(0);
        $this->assertSame('allowlist', AdminSecuritySetting::value('ip_access_mode'));

        $this->artisan('admin:ip-access --blocklist')->assertExitCode(0);
        $this->assertSame('blocklist', AdminSecuritySetting::value('ip_access_mode'));
    }
}
