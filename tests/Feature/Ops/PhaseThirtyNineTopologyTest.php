<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyNineTopologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ops.alerts.correlation.enabled', true);
        config()->set('ops.alerts.correlation.dependencies', ['queue' => ['mysql']]);
    }

    private function openAlert(string $source): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($source.uniqid()),
            'source' => $source, 'severity' => 'critical', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_nodes_and_edges_from_deps(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->openAlert('mysql');

        $data = $this->getJson('/api/ops/alerts/topology')->assertOk()->json('data');

        $edge = collect($data['edges'])->firstWhere('to', 'queue');
        $this->assertSame('mysql', $edge['from']);

        $mysql = collect($data['nodes'])->firstWhere('source', 'mysql');
        $this->assertTrue($mysql['firing']);
        $this->assertSame(1, $mysql['open']);

        $queue = collect($data['nodes'])->firstWhere('source', 'queue');
        $this->assertFalse($queue['firing']);
    }

    public function test_child_suppressed_when_parent_firing(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->openAlert('mysql');
        $this->openAlert('queue');

        $data = $this->getJson('/api/ops/alerts/topology')->assertOk()->json('data');
        $queue = collect($data['nodes'])->firstWhere('source', 'queue');
        $this->assertTrue($queue['firing']);
        $this->assertTrue($queue['suppressed']); // 父 mysql firing
    }

    public function test_no_open_all_not_firing(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $data = $this->getJson('/api/ops/alerts/topology')->assertOk()->json('data');
        foreach ($data['nodes'] as $node) {
            $this->assertFalse($node['firing']);
        }
    }

    public function test_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/topology')->assertStatus(403);
    }
}
