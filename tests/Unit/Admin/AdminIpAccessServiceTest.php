<?php

namespace Tests\Unit\Admin;

use App\Models\AdminIpRule;
use App\Services\Admin\AdminIpAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIpAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AdminIpAccessService
    {
        return app(AdminIpAccessService::class);
    }

    public function test_disabled_allows_everything(): void
    {
        $svc = $this->service();
        $svc->createRule('deny', '203.0.113.0/24', null);
        // enabled defaults to false → 放行全部。

        $this->assertTrue($svc->allowedFor('203.0.113.9'));
        $this->assertSame('disabled', $svc->evaluate('203.0.113.9')['reason']);
    }

    public function test_blocklist_denies_matching_and_allows_others(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);
        $svc->createRule('deny', '203.0.113.0/24', '滥用来源');

        $this->assertFalse($svc->allowedFor('203.0.113.9'));
        $this->assertSame('deny_matched', $svc->evaluate('203.0.113.9')['reason']);
        $this->assertTrue($svc->allowedFor('198.51.100.5'));
        $this->assertSame('no_deny_match', $svc->evaluate('198.51.100.5')['reason']);
    }

    public function test_allowlist_only_permits_listed(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'allowlist']);
        $svc->createRule('allow', '10.0.0.0/8', '办公网');

        $this->assertTrue($svc->allowedFor('10.1.2.3'));
        $this->assertSame('in_allowlist', $svc->evaluate('10.1.2.3')['reason']);
        $this->assertFalse($svc->allowedFor('8.8.8.8'));
        $this->assertSame('not_in_allowlist', $svc->evaluate('8.8.8.8')['reason']);
    }

    public function test_allowlist_with_no_active_allow_rules_fails_open(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'allowlist']);

        // 没有任何 allow 规则 → 绝不锁死所有人。
        $this->assertTrue($svc->allowedFor('8.8.8.8'));
        $this->assertSame('allowlist_empty_failopen', $svc->evaluate('8.8.8.8')['reason']);

        // 只有 deny 规则、无 allow → 命中 deny 拒绝、未命中 fail-open 放行。
        $svc->createRule('deny', '8.8.8.0/24', null);
        $this->assertFalse($svc->allowedFor('8.8.8.8'));
        $this->assertTrue($svc->allowedFor('9.9.9.9'));
        $this->assertSame('allowlist_empty_failopen', $svc->evaluate('9.9.9.9')['reason']);
    }

    public function test_deny_takes_precedence_over_allow(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'allowlist']);
        $svc->createRule('allow', '1.2.3.0/24', null);
        $svc->createRule('deny', '1.2.3.4/32', null);

        $this->assertFalse($svc->allowedFor('1.2.3.4'));
        $this->assertSame('deny_matched', $svc->evaluate('1.2.3.4')['reason']);
        $this->assertTrue($svc->allowedFor('1.2.3.5'));
    }

    public function test_inactive_rules_are_ignored(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);
        $rule = $svc->createRule('deny', '203.0.113.0/24', null);

        $this->assertFalse($svc->allowedFor('203.0.113.9'));

        $svc->toggleRule($rule->id, false);
        $this->assertTrue($svc->allowedFor('203.0.113.9'));
    }

    public function test_single_ip_and_ipv6_cidr(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);
        $svc->createRule('deny', '198.51.100.7', '单个 IP');
        $svc->createRule('deny', '2001:db8::/32', 'IPv6 段');

        $this->assertFalse($svc->allowedFor('198.51.100.7'));
        $this->assertTrue($svc->allowedFor('198.51.100.8'));
        $this->assertFalse($svc->allowedFor('2001:db8::1'));
        $this->assertTrue($svc->allowedFor('2001:dead::1'));
    }

    public function test_invalid_stored_cidr_does_not_crash(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);

        // 绕过服务校验直接写一条非法 CIDR，模拟脏数据。
        AdminIpRule::query()->create(['type' => 'deny', 'cidr' => 'not-a-cidr', 'is_active' => true]);
        $svc->flushCache();

        // 不抛异常、非法规则视为不匹配 → 放行。
        $this->assertTrue($svc->allowedFor('203.0.113.9'));
    }

    public function test_create_rule_rejects_invalid_cidr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->createRule('deny', '999.999.0.0/16', null);
    }

    public function test_create_rule_upserts_same_type_and_cidr(): void
    {
        $svc = $this->service();
        $svc->createRule('deny', '203.0.113.0/24', '第一次');
        $svc->createRule('deny', '203.0.113.0/24', '覆盖');

        $this->assertSame(1, AdminIpRule::query()->count());
        $this->assertSame('覆盖', AdminIpRule::query()->first()->label);
    }

    public function test_is_valid_cidr(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->isValidCidr('10.0.0.0/8'));
        $this->assertTrue($svc->isValidCidr('192.168.1.1'));
        $this->assertTrue($svc->isValidCidr('2001:db8::/32'));
        $this->assertFalse($svc->isValidCidr('10.0.0.0/33'));
        $this->assertFalse($svc->isValidCidr('999.1.1.1'));
        $this->assertFalse($svc->isValidCidr('10.0.0.0/'));
        $this->assertFalse($svc->isValidCidr(''));
    }

    public function test_expired_auto_ban_is_not_enforced(): void
    {
        $svc = $this->service();
        $svc->updateSettings(['ip_access_enabled' => true, 'ip_access_mode' => 'blocklist']);

        // 未过期 auto 封禁 → 拦截。
        $svc->autoBan('203.0.113.9', 60);
        $this->assertFalse($svc->allowedFor('203.0.113.9'));

        // 让它过期 → 放行（evaluate 过滤过期规则）。
        AdminIpRule::query()->where('cidr', '203.0.113.9')->update(['expires_at' => now()->subMinute()]);
        $svc->flushCache();
        $this->assertTrue($svc->allowedFor('203.0.113.9'));
    }

    public function test_delete_expired_auto_bans_only_removes_expired_auto(): void
    {
        $svc = $this->service();
        $svc->autoBan('203.0.113.9', 60);                      // 未过期 auto
        $svc->autoBan('198.51.100.7', 60);
        AdminIpRule::query()->where('cidr', '198.51.100.7')->update(['expires_at' => now()->subMinute()]); // 过期 auto
        $svc->createRule('deny', '10.0.0.0/8', '人工');          // 人工（无过期）

        $removed = $svc->deleteExpiredAutoBans();

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('admin_ip_rules', ['cidr' => '198.51.100.7']);
        $this->assertDatabaseHas('admin_ip_rules', ['cidr' => '203.0.113.9']);
        $this->assertDatabaseHas('admin_ip_rules', ['cidr' => '10.0.0.0/8']);
    }

    public function test_auto_ban_does_not_overwrite_manual_rule(): void
    {
        $svc = $this->service();
        $svc->createRule('deny', '203.0.113.9', '人工永久');

        $this->assertNull($svc->autoBan('203.0.113.9', 60));

        $rule = AdminIpRule::query()->where('cidr', '203.0.113.9')->first();
        $this->assertSame('manual', $rule->source);
        $this->assertNull($rule->expires_at);
    }
}
