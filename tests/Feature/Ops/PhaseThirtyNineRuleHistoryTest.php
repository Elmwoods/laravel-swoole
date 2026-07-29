<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertRule;
use App\Models\OpsAlertRuleChange;
use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyNineRuleHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_records_field_level_changes(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
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

    public function test_toggle_records_is_active(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        app(AlertRuleRegistryService::class)->syncDefaults();

        $this->postJson('/api/ops/alerts/rules/disk_usage/toggle', ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('ops_alert_rule_changes', ['rule_key' => 'disk_usage', 'field' => 'is_active', 'new_value' => '0']);
    }

    public function test_import_records_changes_with_actor(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/rules/import', [
            'rules' => [['key' => 'disk_usage', 'warning_threshold' => 60, 'critical_threshold' => 90, 'is_active' => true]],
        ])->assertOk();

        $this->assertDatabaseHas('ops_alert_rule_changes', ['rule_key' => 'disk_usage', 'field' => 'warning_threshold', 'new_value' => '60', 'actor' => $admin->name]);
    }

    public function test_changes_endpoint_filters_by_key(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        app(AlertRuleRegistryService::class)->syncDefaults();
        $this->postJson('/api/ops/alerts/rules/disk_usage/toggle', ['is_active' => false])->assertOk();
        $this->postJson('/api/ops/alerts/rules/system_cpu/toggle', ['is_active' => false])->assertOk();

        $items = $this->getJson('/api/ops/alerts/rules/changes?key=disk_usage')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('disk_usage', $items[0]['rule_key']);
    }

    public function test_changes_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/rules/changes')->assertStatus(403);
    }
}
