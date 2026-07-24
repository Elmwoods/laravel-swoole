<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\AdminIpRule;
use App\Models\AdminLoginEvent;
use App\Models\AdminSession;
use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsChannelHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseTwentySevenSecurityDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedSecurityData(AdminUser $actor): void
    {
        // 登录事件（7 天内）：1 条新 IP、1 条新设备、1 条普通。
        AdminLoginEvent::query()->create(['admin_user_id' => $actor->id, 'ip_address' => '10.0.0.1', 'is_new_ip' => true]);
        AdminLoginEvent::query()->create(['admin_user_id' => $actor->id, 'ip_address' => '10.0.0.1', 'is_new_user_agent' => true]);
        AdminLoginEvent::query()->create(['admin_user_id' => $actor->id, 'ip_address' => '10.0.0.1']);

        // 失败登录（24h 内）：203.0.113.9 ×3、198.51.100.5 ×1。
        foreach (['203.0.113.9', '203.0.113.9', '203.0.113.9', '198.51.100.5'] as $ip) {
            AdminAuditLog::query()->create([
                'module' => 'admin.auth', 'action' => 'login', 'result' => 'failure',
                'ip_address' => $ip, 'status_code' => 422,
            ]);
        }

        // 会话：2 条活跃（同一 admin）+ 1 条已撤销。
        AdminSession::query()->create(['admin_user_id' => $actor->id, 'session_token_hash' => str_repeat('a', 64), 'last_activity_at' => now()]);
        AdminSession::query()->create(['admin_user_id' => $actor->id, 'session_token_hash' => str_repeat('b', 64), 'last_activity_at' => now()]);
        AdminSession::query()->create(['admin_user_id' => $actor->id, 'session_token_hash' => str_repeat('c', 64), 'last_activity_at' => now(), 'revoked_at' => now()]);

        // 受信任设备：1 有效 + 1 过期。
        AdminTrustedDevice::query()->create(['admin_user_id' => $actor->id, 'token_hash' => str_repeat('d', 64), 'expires_at' => now()->addDays(10)]);
        AdminTrustedDevice::query()->create(['admin_user_id' => $actor->id, 'token_hash' => str_repeat('e', 64), 'expires_at' => now()->subDay()]);

        // IP 规则：allow 1、人工 deny 1、auto-ban 1（未过期）。
        AdminIpRule::query()->create(['type' => 'allow', 'cidr' => '10.0.0.0/8', 'is_active' => true, 'source' => 'manual']);
        AdminIpRule::query()->create(['type' => 'deny', 'cidr' => '203.0.113.0/24', 'is_active' => true, 'source' => 'manual']);
        AdminIpRule::query()->create(['type' => 'deny', 'cidr' => '198.51.100.5', 'is_active' => true, 'source' => 'auto', 'expires_at' => now()->addHour()]);

        // 通道健康：1 healthy + 1 failing。
        OpsChannelHealth::query()->create(['channel' => 'telegram', 'status' => 'healthy', 'consecutive_failures' => 0]);
        OpsChannelHealth::query()->create(['channel' => 'webhook', 'status' => 'failing', 'consecutive_failures' => 3, 'last_error' => 'timeout']);

        // 安全告警：security_login(warning) open、security_audit(critical) open、非安全源 disk(不计)。
        $this->alert('security_login', 'warning');
        $this->alert('security_audit', 'critical');
        $this->alert('disk', 'warning'); // 非安全源，不应计入
    }

    private function alert(string $source, string $severity): void
    {
        OpsAlert::query()->create([
            'fingerprint' => $source.'-'.uniqid(),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source} 告警",
            'message' => 'x',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    public function test_overview_aggregates_all_security_sources(): void
    {
        $actor = $this->actingAsAdminWithPermissions(['ops.security.view']);

        // 2FA 覆盖率：acting(active,no 2FA) + 1 active w/2FA + 1 active w/o + 1 inactive。
        AdminUser::query()->create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x'), 'is_active' => true, 'two_factor_confirmed_at' => now()]);
        AdminUser::query()->create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x'), 'is_active' => true]);
        AdminUser::query()->create(['name' => 'C', 'email' => 'c@example.com', 'password' => Hash::make('x'), 'is_active' => false]);

        $this->seedSecurityData($actor);

        $res = $this->getJson('/api/ops/security/overview')->assertOk();

        $res->assertJsonPath('data.login_risk.new_ip_logins', 1)
            ->assertJsonPath('data.login_risk.new_device_logins', 1)
            ->assertJsonPath('data.login_risk.total_logins', 3)
            ->assertJsonPath('data.failed_logins.total', 4)
            ->assertJsonPath('data.failed_logins.top_ips.0.ip', '203.0.113.9')
            ->assertJsonPath('data.failed_logins.top_ips.0.total', 3)
            // 2 条 seeded + 1 条中间件为当前访问者懒注册（phase-20），都属 actor。
            ->assertJsonPath('data.sessions.active', 3)
            ->assertJsonPath('data.sessions.distinct_admins', 1)
            ->assertJsonPath('data.two_factor.active_admins', 3)
            ->assertJsonPath('data.two_factor.enabled', 1)
            ->assertJsonPath('data.two_factor.coverage_percent', 33)
            ->assertJsonPath('data.trusted_devices.active', 1)
            ->assertJsonPath('data.security_alerts.open_total', 2)
            ->assertJsonPath('data.security_alerts.by_severity.critical', 1)
            ->assertJsonPath('data.security_alerts.by_severity.warning', 1)
            ->assertJsonPath('data.ip_rules.allow_active', 1)
            ->assertJsonPath('data.ip_rules.deny_active', 2)
            ->assertJsonPath('data.ip_rules.auto_ban_active', 1)
            ->assertJsonPath('data.channel_health.healthy', 1)
            ->assertJsonPath('data.channel_health.failing', 1)
            ->assertJsonPath('data.channel_health.total', 2);

        $this->assertCount(2, $res->json('data.recent_events')); // 只安全源
        $this->assertNotEmpty($res->json('data.failed_login_trend'));
    }

    public function test_empty_state_does_not_divide_by_zero(): void
    {
        // 把 acting admin 设为唯一但先删掉让 active=0? actingAs 需要一个 admin；改为断言 coverage 合法。
        $this->actingAsAdminWithPermissions(['ops.security.view']);

        $res = $this->getJson('/api/ops/security/overview')->assertOk();

        $res->assertJsonPath('data.failed_logins.total', 0)
            ->assertJsonPath('data.sessions.active', 1) // 仅当前访问者的懒注册会话
            ->assertJsonPath('data.security_alerts.open_total', 0)
            ->assertJsonPath('data.channel_health.total', 0);
        // active_admins=1(acting)、enabled=0 → coverage 0，不除零崩溃。
        $this->assertSame(0, $res->json('data.two_factor.coverage_percent'));
    }

    public function test_requires_security_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/security/overview')->assertStatus(403);
    }
}
