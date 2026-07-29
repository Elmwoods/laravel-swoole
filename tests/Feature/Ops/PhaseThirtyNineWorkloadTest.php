<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhaseThirtyNineWorkloadTest extends TestCase
{
    use RefreshDatabase;

    private function alert(Carbon $born, ?string $assigned = null): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1(uniqid()),
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'resolved', 'hit_count' => 1, 'last_seen_at' => $born, 'assigned_to' => $assigned,
        ]);
        $alert->created_at = $born;
        $alert->saveQuietly();

        return $alert;
    }

    private function event(OpsAlert $alert, string $action, string $actor, Carbon $at): void
    {
        OpsAlertEvent::query()->create([
            'alert_id' => $alert->id, 'action' => $action, 'actor' => $actor, 'created_at' => $at,
        ]);
    }

    public function test_workload_aggregates_by_actor(): void
    {
        $born = now()->subHours(3);
        $a1 = $this->alert($born, 'alice');
        $this->event($a1, 'acknowledged', 'alice', $born->copy()->addSeconds(120));
        $this->event($a1, 'resolved', 'alice', $born->copy()->addSeconds(600));

        $a2 = $this->alert($born, 'alice');
        $this->event($a2, 'acknowledged', 'alice', $born->copy()->addSeconds(240));

        // 系统 actor 不计入。
        $a3 = $this->alert($born);
        $this->event($a3, 'auto_resolved', 'ops-auto-resolver', $born->copy()->addSeconds(300));

        $summary = app(AlertCenterService::class)->workloadSummary(7);
        $alice = collect($summary['people'])->firstWhere('person', 'alice');

        $this->assertSame(2, $alice['acknowledged_count']);
        $this->assertSame(180, $alice['avg_ack_seconds']); // (120+240)/2
        $this->assertSame(1, $alice['resolved_count']);
        $this->assertSame(600, $alice['avg_resolve_seconds']);
        $this->assertSame(2, $alice['assigned_count']);

        // 无 ops-auto-resolver 这一"人"。
        $this->assertNull(collect($summary['people'])->firstWhere('person', 'ops-auto-resolver'));
    }

    public function test_window_excludes_old_events(): void
    {
        $born = now()->subDays(40);
        $a = $this->alert($born, 'bob');
        $this->event($a, 'resolved', 'bob', $born->copy()->addSeconds(60));

        $summary = app(AlertCenterService::class)->workloadSummary(7);
        $this->assertSame([], $summary['people']);
    }

    public function test_endpoint(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/workload?days=30')
            ->assertOk()
            ->assertJsonPath('data.days', 30)
            ->assertJsonPath('data.window_days', 30);
    }

    public function test_endpoint_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/workload')->assertStatus(403);
    }
}
