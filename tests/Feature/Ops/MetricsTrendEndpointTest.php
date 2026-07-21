<?php

namespace Tests\Feature\Ops;

use App\Models\OpsMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MetricsTrendEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_trend_requires_permission(): void
    {
        $this->getJson('/api/ops/system/metrics-trend')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/system/metrics-trend')->assertStatus(403);
    }

    public function test_metrics_trend_returns_daily_averages(): void
    {
        $this->actingAsAdminWithPermissions(['ops.system.view']);

        $this->sampleAt(Carbon::now(), cpu: 2.0, mem: 40.0);
        $this->sampleAt(Carbon::now(), cpu: 4.0, mem: 60.0);
        $this->sampleAt(Carbon::now()->subDays(1), cpu: 1.0, mem: 20.0);

        $buckets = $this->getJson('/api/ops/system/metrics-trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        $today = collect($buckets)->firstWhere('date', Carbon::now()->toDateString());
        $this->assertEqualsWithDelta(3.0, $today['cpu_load'], 0.001);
        $this->assertEqualsWithDelta(50.0, $today['memory_used_percent'], 0.001);
    }

    private function sampleAt(Carbon $at, float $cpu, float $mem): void
    {
        $sample = OpsMetricSample::query()->create([
            'cpu_load' => $cpu,
            'load1' => $cpu,
            'memory_used_percent' => $mem,
            'swap_used_percent' => 0,
            'captured_at' => $at,
        ]);
        $sample->forceFill(['captured_at' => $at])->save();
    }
}
