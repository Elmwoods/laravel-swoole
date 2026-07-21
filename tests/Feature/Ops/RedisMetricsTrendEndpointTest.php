<?php

namespace Tests\Feature\Ops;

use App\Models\OpsRedisMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RedisMetricsTrendEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_trend_requires_permission(): void
    {
        $this->getJson('/api/ops/redis-metrics/trend')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/redis-metrics/trend')->assertStatus(403);
    }

    public function test_trend_returns_daily_averages(): void
    {
        $this->actingAsAdminWithPermissions(['ops.system.view']);

        $this->sampleAt(Carbon::now(), ops: 200, hit: 80.0);
        $this->sampleAt(Carbon::now(), ops: 400, hit: 90.0);
        $this->sampleAt(Carbon::now()->subDays(1), ops: 100, hit: 70.0);

        $buckets = $this->getJson('/api/ops/redis-metrics/trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        $today = collect($buckets)->firstWhere('date', Carbon::now()->toDateString());
        $this->assertEqualsWithDelta(300.0, $today['ops'], 0.001);
        $this->assertEqualsWithDelta(85.0, $today['hit_rate'], 0.001);
    }

    private function sampleAt(Carbon $at, int $ops, float $hit): void
    {
        $sample = OpsRedisMetricSample::query()->create([
            'ops' => $ops,
            'clients' => 10,
            'memory_mb' => 20,
            'hit_rate' => $hit,
            'captured_at' => $at,
        ]);
        $sample->forceFill(['captured_at' => $at])->save();
    }
}
