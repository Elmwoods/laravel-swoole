<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertRule;
use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyThreeRuleIoTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_tunable_fields_for_all_rules(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $payload = $this->getJson('/api/ops/alerts/rules/export')->assertOk()->json('data');

        $this->assertArrayHasKey('exported_at', $payload);
        $this->assertCount(count(app(AlertRuleRegistryService::class)->allowedKeys()), $payload['rules']);

        $disk = collect($payload['rules'])->firstWhere('key', 'disk_usage');
        $this->assertSame(['key', 'name', 'warning_threshold', 'critical_threshold', 'is_active'], array_keys($disk));
    }

    public function test_import_applies_valid_rules_and_reports_skips(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $result = $this->postJson('/api/ops/alerts/rules/import', [
            'rules' => [
                ['key' => 'disk_usage', 'warning_threshold' => 70, 'critical_threshold' => 90, 'is_active' => false],
                ['key' => 'not_a_real_rule', 'warning_threshold' => 5, 'critical_threshold' => null, 'is_active' => true],
                ['key' => 'system_cpu', 'warning_threshold' => 200, 'critical_threshold' => null, 'is_active' => true], // over max 100
                ['key' => 'disk_usage', 'warning_threshold' => 80, 'critical_threshold' => 50, 'is_active' => true], // critical < warning
            ],
        ])->assertOk()->json('data');

        $this->assertSame(1, $result['applied']);
        $this->assertSame(4, $result['total']);
        $reasons = collect($result['skipped'])->pluck('reason', 'key');
        $this->assertSame('unknown_key', $reasons['not_a_real_rule']);
        $this->assertSame('invalid', $reasons['system_cpu']);

        $disk = OpsAlertRule::query()->where('key', 'disk_usage')->firstOrFail();
        $this->assertEquals(70, $disk->warning_threshold);
        $this->assertEquals(90, $disk->critical_threshold);
        $this->assertFalse((bool) $disk->is_active);

        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.alerts',
            'action' => 'rule_import',
            'result' => 'success',
        ]);
    }

    public function test_import_requires_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/rules/import', [
            'rules' => [['key' => 'disk_usage', 'warning_threshold' => 70, 'critical_threshold' => 90, 'is_active' => true]],
        ])->assertStatus(403);
    }

    public function test_export_requires_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/alerts/rules/export')->assertStatus(403);
    }

    public function test_import_validates_structure(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/rules/import', ['rules' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rules']);
    }
}
