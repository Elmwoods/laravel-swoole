<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditPreset;
use App\Services\Admin\AdminAuditPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTwentyOneAuditPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_and_list_presets_scoped_to_owner(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        $this->postJson('/api/admin/audit-logs/presets', [
            'name' => '登录失败',
            'filters' => ['module' => 'admin.auth', 'result' => 'failure', 'from' => '2026-07-01 00:00:00'],
        ])
            ->assertOk()
            ->assertJsonPath('data.preset.name', '登录失败')
            ->assertJsonPath('data.preset.filters.module', 'admin.auth');

        $items = $this->getJson('/api/admin/audit-logs/presets')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('登录失败', $items[0]['name']);
        $this->assertSame('failure', $items[0]['filters']['result']);
        $this->assertArrayNotHasKey('page', $items[0]['filters']);

        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'admin.audit',
            'action' => 'preset_create',
            'result' => 'success',
        ]);
    }

    public function test_presets_are_isolated_per_admin(): void
    {
        $owner = $this->actingAsAdminWithPermissions(['admin.audit.view']);
        app(AdminAuditPresetService::class)->save($owner, '别人的预设', ['module' => 'ops.octane']);

        // 切到另一个管理员：看不到 owner 的预设。
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        $this->getJson('/api/admin/audit-logs/presets')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_saving_same_name_upserts(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        $this->postJson('/api/admin/audit-logs/presets', ['name' => 'X', 'filters' => ['module' => 'a']])->assertOk();
        $this->postJson('/api/admin/audit-logs/presets', ['name' => 'X', 'filters' => ['module' => 'b']])->assertOk();

        $items = $this->getJson('/api/admin/audit-logs/presets')->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('b', $items[0]['filters']['module']);
    }

    public function test_delete_is_owner_scoped(): void
    {
        $owner = $this->actingAsAdminWithPermissions(['admin.audit.view']);
        $preset = app(AdminAuditPresetService::class)->save($owner, 'mine', ['module' => 'a']);

        // 另一个管理员删不掉别人的预设。
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        $this->deleteJson("/api/admin/audit-logs/presets/{$preset->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', false);
        $this->assertModelExists($preset->fresh());

        // owner 可删。
        $this->actingAs($owner, 'admin')->withSession(['admin_session_version' => (int) $owner->session_version]);
        $this->deleteJson("/api/admin/audit-logs/presets/{$preset->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertNull(AdminAuditPreset::query()->find($preset->id));
    }

    public function test_filters_are_whitelisted_and_pagination_stripped(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        $this->postJson('/api/admin/audit-logs/presets', [
            'name' => 'clean',
            'filters' => ['module' => 'ops.octane', 'page' => 5, 'per_page' => 50, 'bogus' => 'x', 'keyword' => ''],
        ])->assertOk();

        $filters = AdminAuditPreset::query()->firstOrFail()->filters;
        $this->assertSame(['module' => 'ops.octane'], $filters);
    }

    public function test_invalid_filter_value_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        $this->postJson('/api/admin/audit-logs/presets', [
            'name' => 'bad',
            'filters' => ['result' => 'maybe'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filters.result']);

        $this->postJson('/api/admin/audit-logs/presets', ['filters' => ['module' => 'a']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_requires_audit_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/admin/audit-logs/presets')->assertStatus(403);
        $this->postJson('/api/admin/audit-logs/presets', ['name' => 'x'])->assertStatus(403);
    }
}
