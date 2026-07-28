<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyFiveAlertGroupTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $source, string $severity, string $status = 'open', ?string $assigned = null): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($source.$severity.uniqid()),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source}-{$severity}",
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'assigned_to' => $assigned,
            'last_seen_at' => now(),
        ]);
    }

    public function test_group_by_source(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->alert('disk', 'critical');
        $this->alert('disk', 'warning');
        $this->alert('queue', 'info');
        $this->alert('disk', 'critical', 'resolved'); // 不计入 open

        $data = $this->getJson('/api/ops/alerts/groups?by=source')->assertOk()->json('data');
        $this->assertSame('source', $data['by']);

        $disk = collect($data['groups'])->firstWhere('group', 'disk');
        $this->assertSame(2, $disk['total']);
        $this->assertSame(1, $disk['critical']);
        $this->assertSame(1, $disk['warning']);
        $this->assertCount(2, $disk['samples']);
        // 按 total 倒序：disk(2) 在 queue(1) 前。
        $this->assertSame('disk', $data['groups'][0]['group']);
    }

    public function test_group_by_assigned_to_buckets_unassigned(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->alert('disk', 'critical', 'open', 'alice');
        $this->alert('queue', 'warning', 'open', null);

        $data = $this->getJson('/api/ops/alerts/groups?by=assigned_to')->assertOk()->json('data');
        $groups = collect($data['groups']);

        $this->assertSame(1, $groups->firstWhere('group', 'alice')['total']);
        $this->assertSame(1, $groups->firstWhere('group', '__unassigned__')['total']);
    }

    public function test_invalid_by_falls_back_to_source(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alert('disk', 'critical');

        $this->getJson('/api/ops/alerts/groups?by=bogus')->assertOk()->assertJsonPath('data.by', 'source');
    }

    public function test_empty_returns_empty_groups(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/groups')->assertOk()->assertJsonPath('data.groups', []);
    }

    public function test_requires_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/groups')->assertStatus(403);
    }
}
