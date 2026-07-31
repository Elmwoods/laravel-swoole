<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertRule;
use App\Models\OpsAlertRuleChange;
use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 39「告警规则变更历史」测试。
 *
 * 覆盖 OpsAlertRuleChange 对规则改动的字段级留痕：update/toggle/import 三条写路径均记录
 *（field/new_value/actor），且只记录真正发生变化的字段；变更列表接口支持按规则 key 过滤，
 * 并受 ops.alerts.view 权限保护。
 */
class PhaseThirtyNineRuleHistoryTest extends TestCase
{
    use RefreshDatabase;

    // 验证：更新规则时按字段级记录变更——warning_threshold、is_active 有变会记，critical_threshold 未变不记；new_value 与 actor 正确。
    public function test_update_records_field_level_changes(): void
    {
        // 写操作需 view+manage；返回的 admin 用于断言变更记录的 actor 是当前操作者。
        $admin = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        // 先同步内置规则，确保 disk_usage 存在可供更新。
        app(AlertRuleRegistryService::class)->syncDefaults();
        $rule = OpsAlertRule::query()->where('key', 'disk_usage')->firstOrFail();

        $this->putJson('/api/ops/alerts/rules/disk_usage', [
            'warning_threshold' => 70,
            'critical_threshold' => (float) $rule->critical_threshold, // unchanged
            'is_active' => false,
        ])->assertOk();

        $changes = OpsAlertRuleChange::query()->where('rule_key', 'disk_usage')->get();
        $fields = $changes->pluck('field')->all();
        $this->assertContains('warning_threshold', $fields);
        $this->assertContains('is_active', $fields);
        $this->assertNotContains('critical_threshold', $fields); // 未变化不记

        $warn = $changes->firstWhere('field', 'warning_threshold');
        $this->assertSame('70', $warn->new_value);
        $this->assertSame($admin->name, $warn->actor);
    }

    // 验证：toggle 接口切换启用状态会记录一条 field=is_active、new_value='0' 的变更。
    public function test_toggle_records_is_active(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        app(AlertRuleRegistryService::class)->syncDefaults();

        $this->postJson('/api/ops/alerts/rules/disk_usage/toggle', ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('ops_alert_rule_changes', ['rule_key' => 'disk_usage', 'field' => 'is_active', 'new_value' => '0']);
    }

    // 验证：import 批量导入规则也会记录字段级变更，且 actor 为当前管理员。
    public function test_import_records_changes_with_actor(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/rules/import', [
            'rules' => [['key' => 'disk_usage', 'warning_threshold' => 60, 'critical_threshold' => 90, 'is_active' => true]],
        ])->assertOk();

        $this->assertDatabaseHas('ops_alert_rule_changes', ['rule_key' => 'disk_usage', 'field' => 'warning_threshold', 'new_value' => '60', 'actor' => $admin->name]);
    }

    // 验证：变更列表接口支持按 key 过滤——对 disk_usage 与 system_cpu 各改一次后，按 key=disk_usage 只返回该规则的 1 条记录。
    public function test_changes_endpoint_filters_by_key(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        app(AlertRuleRegistryService::class)->syncDefaults();
        // 制造两条不同规则的变更，以验证过滤确实生效。
        $this->postJson('/api/ops/alerts/rules/disk_usage/toggle', ['is_active' => false])->assertOk();
        $this->postJson('/api/ops/alerts/rules/system_cpu/toggle', ['is_active' => false])->assertOk();

        $items = $this->getJson('/api/ops/alerts/rules/changes?key=disk_usage')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('disk_usage', $items[0]['rule_key']);
    }

    // 验证：无任何权限时访问变更列表返回 403（受 ops.alerts.view 保护）。
    public function test_changes_requires_view(): void
    {
        // 空权限管理员，用于验证权限门。
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/rules/changes')->assertStatus(403);
    }
}
