<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyThreeAssignTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlert(string $fingerprint, ?string $assignedTo = null): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($fingerprint),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => $fingerprint,
            'message' => 'msg',
            'context' => ['target' => '/'],
            'status' => 'open',
            'hit_count' => 1,
            'assigned_to' => $assignedTo,
            'assigned_at' => $assignedTo ? now() : null,
            'last_seen_at' => now(),
        ]);
    }

    public function test_filter_by_assigned_to_returns_only_that_persons_alerts(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->makeAlert('a-one', 'alice');
        $this->makeAlert('b-one', 'bob');
        $this->makeAlert('c-one', null);

        $items = $this->getJson('/api/ops/alerts?assigned_to=alice')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('alice', $items[0]['assigned_to']);
    }

    public function test_filter_unassigned_returns_only_unassigned(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->makeAlert('a-two', 'alice');
        $this->makeAlert('c-two', null);

        $items = $this->getJson('/api/ops/alerts?assigned=unassigned')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertNull($items[0]['assigned_to']);
    }

    public function test_assignees_endpoint_returns_distinct_sorted_list(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->makeAlert('a-three', 'bob');
        $this->makeAlert('b-three', 'alice');
        $this->makeAlert('c-three', 'alice');
        $this->makeAlert('d-three', null);

        $items = $this->getJson('/api/ops/alerts/assignees')->assertOk()->json('data.items');
        $this->assertSame(['alice', 'bob'], $items);
    }

    public function test_endpoints_require_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/alerts/assignees')->assertStatus(403);
    }
}
