<?php

namespace Tests\Unit\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminIpRule;
use App\Services\Admin\AdminIpAccessService;
use App\Services\Admin\AdminIpAutoBanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminIpAutoBanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // 启用 IP 准入（让封禁能被 evaluate 生效）+ 自动封禁；缩小阈值便于测试。
        config()->set('ops.security.auto_ban.threshold', 3);
        config()->set('ops.security.auto_ban.window_minutes', 10);
        config()->set('ops.security.auto_ban.ban_minutes', 60);
        config()->set('ops.security.auto_ban.never_ban', ['127.0.0.1/8', '::1']);
        config()->set('ops.security.auto_ban.state_file', storage_path('app/testing-auto-ban-state-'.uniqid().'.json'));

        $svc = app(AdminIpAccessService::class);
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist', 'auto_ban_enabled' => true]);
        // 首跑初始化游标（无历史行时 max id = 0）。
        app(AdminIpAutoBanService::class)->scan();
    }

    private function service(): AdminIpAutoBanService
    {
        return app(AdminIpAutoBanService::class);
    }

    private function failedLogin(string $ip, int $count, ?string $email = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            AdminAuditLog::query()->create([
                'module' => 'admin.auth',
                'action' => 'login',
                'result' => 'failure',
                'ip_address' => $ip,
                'admin_email' => $email ?? "attacker{$i}@example.com",
                'status_code' => 422,
            ]);
        }
    }

    public function test_ip_over_threshold_is_auto_banned(): void
    {
        $this->failedLogin('203.0.113.9', 3);

        $result = $this->service()->scan();

        $this->assertSame(1, $result['banned']);
        $rule = AdminIpRule::query()->where('type', 'deny')->where('cidr', '203.0.113.9')->first();
        $this->assertNotNull($rule);
        $this->assertSame('auto', $rule->source);
        $this->assertTrue($rule->expires_at->isFuture());
        $this->assertFalse(app(AdminIpAccessService::class)->allowedFor('203.0.113.9'));

        $this->assertDatabaseHas('ops_alerts', ['source' => 'security_access']);
    }

    public function test_ip_below_threshold_is_not_banned(): void
    {
        $this->failedLogin('203.0.113.9', 2);

        $result = $this->service()->scan();

        $this->assertSame(0, $result['banned']);
        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '203.0.113.9']);
    }

    public function test_never_ban_ip_is_skipped(): void
    {
        $this->failedLogin('127.0.0.1', 5);

        $this->service()->scan();

        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '127.0.0.1']);
    }

    public function test_allowlisted_ip_is_not_banned(): void
    {
        app(AdminIpAccessService::class)->createRule('allow', '198.51.100.0/24', '办公网');
        $this->failedLogin('198.51.100.7', 5);

        $this->service()->scan();

        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '198.51.100.7']);
    }

    public function test_manual_deny_rule_is_not_overwritten(): void
    {
        $manual = app(AdminIpAccessService::class)->createRule('deny', '203.0.113.9', '人工永久封禁');
        $this->assertNull($manual->expires_at);
        $this->assertSame('manual', $manual->source);

        $this->failedLogin('203.0.113.9', 5);
        $this->service()->scan();

        $fresh = $manual->fresh();
        $this->assertSame('manual', $fresh->source);
        $this->assertNull($fresh->expires_at);
    }

    public function test_existing_auto_ban_is_extended(): void
    {
        $this->failedLogin('203.0.113.9', 3);
        $this->service()->scan();
        $first = AdminIpRule::query()->where('cidr', '203.0.113.9')->first()->expires_at;

        // 再触发：续期。
        $this->failedLogin('203.0.113.9', 3);
        $this->travelTo(now()->addMinutes(2));
        $this->service()->scan();
        $second = AdminIpRule::query()->where('cidr', '203.0.113.9')->first()->expires_at;

        $this->assertTrue($second->greaterThan($first));
    }

    public function test_disabled_does_not_ban(): void
    {
        app(AdminIpAccessService::class)->updateSettings(['auto_ban_enabled' => false]);
        $this->failedLogin('203.0.113.9', 5);

        $result = $this->service()->scan();

        $this->assertFalse($result['enabled']);
        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '203.0.113.9']);
    }

    public function test_dry_run_does_not_persist_ban(): void
    {
        $this->failedLogin('203.0.113.9', 5);

        $result = $this->service()->scan(true);

        $this->assertSame(1, $result['banned']);
        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '203.0.113.9']);
    }

    public function test_login_denied_rows_do_not_count(): void
    {
        // 已封禁来源的 403 login_denied 不计入暴增（不在 action 集合）。
        for ($i = 0; $i < 5; $i++) {
            AdminAuditLog::query()->create([
                'module' => 'admin.auth',
                'action' => 'login_denied',
                'result' => 'failure',
                'ip_address' => '203.0.113.9',
                'status_code' => 403,
            ]);
        }

        $this->service()->scan();

        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '203.0.113.9']);
    }
}
