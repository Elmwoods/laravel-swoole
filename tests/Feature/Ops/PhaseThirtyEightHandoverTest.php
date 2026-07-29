<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use App\Models\OpsShiftHandover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyEightHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function openAlert(string $severity = 'warning'): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk', 'severity' => $severity, 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_create_with_explicit_from_to_snapshots_open_count(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->openAlert();
        $this->openAlert();

        $this->postJson('/api/ops/alerts/handovers', ['from_assignee' => 'alice', 'to_assignee' => 'bob', 'note' => '注意磁盘'])
            ->assertOk()
            ->assertJsonPath('data.handover.to_assignee', 'bob')
            ->assertJsonPath('data.handover.open_alert_count', 2);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'handover_create', 'result' => 'success']);
    }

    public function test_to_defaults_to_current_on_call(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        OpsOnCallShift::query()->create([
            'assignee' => 'oncall-carol', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'recurrence' => 'once', 'is_active' => true,
        ]);

        $this->postJson('/api/ops/alerts/handovers', ['from_assignee' => 'alice'])
            ->assertOk()
            ->assertJsonPath('data.handover.to_assignee', 'oncall-carol');
    }

    public function test_from_defaults_to_last_handover_to(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        OpsShiftHandover::query()->create(['from_assignee' => 'x', 'to_assignee' => 'prev-person', 'open_alert_count' => 0]);

        $this->postJson('/api/ops/alerts/handovers', ['to_assignee' => 'next-person'])->assertOk();

        $latest = OpsShiftHandover::query()->latest('id')->first();
        $this->assertSame('prev-person', $latest->from_assignee);
        $this->assertSame('next-person', $latest->to_assignee);
    }

    public function test_422_when_no_current_on_call_and_no_to(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->postJson('/api/ops/alerts/handovers', ['from_assignee' => 'alice'])->assertStatus(422);
    }

    public function test_list_newest_first(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        OpsShiftHandover::query()->create(['to_assignee' => 'a', 'open_alert_count' => 0]);
        OpsShiftHandover::query()->create(['to_assignee' => 'b', 'open_alert_count' => 0]);

        $items = $this->getJson('/api/ops/alerts/handovers')->assertOk()->json('data.items');
        $this->assertSame('b', $items[0]['to_assignee']);
    }

    public function test_create_requires_manage(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->postJson('/api/ops/alerts/handovers', ['to_assignee' => 'bob'])->assertStatus(403);
    }
}
