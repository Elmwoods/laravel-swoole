<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyEightSimilarTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $source, string $status, string $title = 't'): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.$status.$title.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => $title, 'message' => 'm',
            'status' => $status, 'hit_count' => 1, 'last_seen_at' => now(),
            'acknowledged_by' => $status === 'resolved' ? 'alice' : null,
            'acknowledge_note' => $status === 'resolved' ? '重启服务' : null,
        ]);
    }

    public function test_similar_returns_same_source_resolved_with_notes(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $current = $this->alert('disk', 'open');
        $past = $this->alert('disk', 'resolved');
        OpsAlertNote::query()->create(['alert_id' => $past->id, 'author' => 'bob', 'body' => '清了日志']);
        $this->alert('queue', 'resolved'); // 不同来源
        $this->alert('disk', 'open');      // 非 resolved

        $items = $this->getJson("/api/ops/alerts/{$current->id}/similar")->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame($past->id, $items[0]['id']);
        $this->assertSame('alice', $items[0]['acknowledged_by']);
        $this->assertCount(1, $items[0]['notes']);
        $this->assertSame('清了日志', $items[0]['notes'][0]['body']);
    }

    public function test_empty_when_no_similar(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $alert = $this->alert('disk', 'open');
        $this->getJson("/api/ops/alerts/{$alert->id}/similar")->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_requires_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $alert = $this->alert('disk', 'open');
        $this->getJson("/api/ops/alerts/{$alert->id}/similar")->assertStatus(403);
    }
}
