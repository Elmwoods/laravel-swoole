<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtySixBatchOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
    }

    private function alert(string $source, string $severity = 'warning', string $status = 'open'): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.$severity.uniqid()),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source}-t",
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    public function test_batch_acknowledge_group_by_source(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $a = $this->alert('disk');
        $b = $this->alert('disk');
        $other = $this->alert('queue');
        $resolved = $this->alert('disk', 'warning', 'resolved');

        $this->postJson('/api/ops/alerts/batch/acknowledge', ['by' => 'source', 'group' => 'disk', 'note' => '批量'])
            ->assertOk()
            ->assertJsonPath('data.affected', 2)
            ->assertJsonPath('data.capped', false);

        $this->assertSame('acknowledged', $a->refresh()->status);
        $this->assertSame('acknowledged', $b->refresh()->status);
        $this->assertSame('open', $other->refresh()->status);
        $this->assertSame('resolved', $resolved->refresh()->status);

        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $a->id, 'action' => 'acknowledged']);
        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'batch_acknowledge', 'result' => 'success']);
    }

    public function test_batch_assign_group(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $a = $this->alert('disk', 'critical');
        $b = $this->alert('disk', 'critical');

        $this->postJson('/api/ops/alerts/batch/assign', ['by' => 'severity', 'group' => 'critical', 'assigned_to' => 'alice'])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);

        $this->assertSame('alice', $a->refresh()->assigned_to);
        $this->assertSame('alice', $b->refresh()->assigned_to);
    }

    public function test_batch_assign_requires_assigned_to(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->alert('disk');

        $this->postJson('/api/ops/alerts/batch/assign', ['by' => 'source', 'group' => 'disk'])
            ->assertStatus(422);
    }

    public function test_batch_silence_group_creates_silence(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/batch/silence', ['by' => 'source', 'group' => 'disk', 'minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.silence_id', fn ($id) => $id !== null);

        $silence = OpsAlertSilence::query()->firstOrFail();
        $this->assertSame(['disk'], $silence->sources);
        $this->assertTrue($silence->is_active);
    }

    public function test_batch_requires_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/batch/acknowledge', ['by' => 'source', 'group' => 'disk'])->assertStatus(403);
    }

    public function test_invalid_by_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/batch/acknowledge', ['by' => 'bogus', 'group' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['by']);
    }
}
