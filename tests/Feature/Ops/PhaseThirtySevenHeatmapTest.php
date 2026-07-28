<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhaseThirtySevenHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private function alertAt(string $source, Carbon $at): void
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1($source.$at->timestamp.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => $at,
        ]);
        $alert->forceFill(['created_at' => $at])->save();
    }

    public function test_buckets_count_by_hour_and_dow(): void
    {
        // 2026-07-29 是周三（dayOfWeek=3），14:00。
        $this->alertAt('disk', Carbon::parse('2026-07-29 14:00:00'));
        $this->alertAt('disk', Carbon::parse('2026-07-29 14:30:00'));
        $this->alertAt('queue', Carbon::parse('2026-07-27 09:00:00')); // 周一 dow=1

        $summary = app(AlertCenterService::class)->heatmapSummary(30);

        $cell = collect($summary['buckets'])->firstWhere(fn ($b) => $b['dow'] === 3 && $b['hour'] === 14);
        $this->assertSame(2, $cell['count']);
        $mon = collect($summary['buckets'])->firstWhere(fn ($b) => $b['dow'] === 1 && $b['hour'] === 9);
        $this->assertSame(1, $mon['count']);

        // 7×24 = 168 桶。
        $this->assertCount(168, $summary['buckets']);
        // 最吵来源 disk(2) 在前。
        $this->assertSame('disk', $summary['sources'][0]['source']);
        $this->assertSame(2, $summary['sources'][0]['total']);
    }

    public function test_empty_returns_zero_matrix(): void
    {
        $summary = app(AlertCenterService::class)->heatmapSummary(7);
        $this->assertCount(168, $summary['buckets']);
        $this->assertSame(0, collect($summary['buckets'])->sum('count'));
        $this->assertSame([], $summary['sources']);
    }

    public function test_endpoint(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alertAt('disk', now());

        $this->getJson('/api/ops/alerts/heatmap?days=30')
            ->assertOk()
            ->assertJsonPath('data.days', 30)
            ->assertJsonCount(168, 'data.buckets');
    }

    public function test_endpoint_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/heatmap')->assertStatus(403);
    }
}
