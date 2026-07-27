<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertPreset;
use App\Services\Ops\OpsAlertPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyOneAlertPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_and_list_presets_scoped_to_owner(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/presets', [
            'name' => '严重磁盘',
            'filters' => ['status' => 'open', 'severity' => 'critical', 'source' => 'disk'],
        ])
            ->assertOk()
            ->assertJsonPath('data.preset.name', '严重磁盘')
            ->assertJsonPath('data.preset.filters.severity', 'critical');

        $items = $this->getJson('/api/ops/alerts/presets')->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('严重磁盘', $items[0]['name']);
        $this->assertSame('disk', $items[0]['filters']['source']);

        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.alerts',
            'action' => 'preset_create',
            'result' => 'success',
        ]);
    }

    public function test_presets_are_isolated_per_admin(): void
    {
        $owner = $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        app(OpsAlertPresetService::class)->save($owner, '别人的', ['severity' => 'warning']);

        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/presets')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_saving_same_name_upserts(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/presets', ['name' => 'X', 'filters' => ['source' => 'disk']])->assertOk();
        $this->postJson('/api/ops/alerts/presets', ['name' => 'X', 'filters' => ['source' => 'redis']])->assertOk();

        $items = $this->getJson('/api/ops/alerts/presets')->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('redis', $items[0]['filters']['source']);
    }

    public function test_delete_is_owner_scoped(): void
    {
        $owner = $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $preset = app(OpsAlertPresetService::class)->save($owner, 'mine', ['source' => 'disk']);

        // 另一个管理员删不掉别人的。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->deleteJson("/api/ops/alerts/presets/{$preset->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', false);
        $this->assertModelExists($preset->fresh());

        // owner 可删。
        $this->actingAs($owner, 'admin')->withSession(['admin_session_version' => (int) $owner->session_version]);
        $this->deleteJson("/api/ops/alerts/presets/{$preset->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertNull(OpsAlertPreset::query()->find($preset->id));
    }

    public function test_filters_are_whitelisted_and_pagination_stripped(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/presets', [
            'name' => 'clean',
            'filters' => ['status' => 'open', 'page' => 5, 'per_page' => 50, 'bogus' => 'x', 'severity' => ''],
        ])->assertOk();

        $filters = OpsAlertPreset::query()->firstOrFail()->filters;
        $this->assertSame(['status' => 'open'], $filters);
    }

    public function test_invalid_filter_value_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/presets', ['name' => 'bad', 'filters' => ['severity' => 'maybe']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filters.severity']);

        $this->postJson('/api/ops/alerts/presets', ['filters' => ['source' => 'disk']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_requires_alerts_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/alerts/presets')->assertStatus(403);
        $this->postJson('/api/ops/alerts/presets', ['name' => 'x'])->assertStatus(403);
    }
}
