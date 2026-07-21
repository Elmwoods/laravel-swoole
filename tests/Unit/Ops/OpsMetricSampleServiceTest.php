<?php

namespace Tests\Unit\Ops;

use App\Models\OpsMetricSample;
use App\Services\Ops\OpsMetricSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OpsMetricSampleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_derive_metrics_computes_percentages(): void
    {
        $metrics = app(OpsMetricSampleService::class)->deriveMetrics([
            'cpu' => 1.5,
            'load' => [1.5, 1.0, 0.5],
            'memory' => ['total' => 1000, 'available' => 250],
            'swap' => ['total' => 200, 'free' => 50],
        ]);

        $this->assertSame(1.5, $metrics['cpu_load']);
        $this->assertSame(1.5, $metrics['load1']);
        $this->assertSame(75.0, $metrics['memory_used_percent']);
        $this->assertSame(75.0, $metrics['swap_used_percent']);
    }

    public function test_derive_metrics_handles_zero_swap_and_missing_fields(): void
    {
        $metrics = app(OpsMetricSampleService::class)->deriveMetrics([
            'memory' => ['total' => 0, 'available' => 0],
            'swap' => ['total' => 0, 'free' => 0],
        ]);

        $this->assertSame(0.0, $metrics['cpu_load']);
        $this->assertSame(0.0, $metrics['memory_used_percent']);
        $this->assertSame(0.0, $metrics['swap_used_percent']);
    }

    public function test_record_persists_a_sample_row(): void
    {
        $sample = app(OpsMetricSampleService::class)->record([
            'cpu' => 2.0,
            'load' => [2.0, 1.0, 1.0],
            'memory' => ['total' => 1000, 'available' => 500],
            'swap' => ['total' => 100, 'free' => 100],
        ]);

        $this->assertDatabaseHas('ops_metric_samples', [
            'id' => $sample->id,
            'cpu_load' => 2.0,
            'memory_used_percent' => 50.0,
            'swap_used_percent' => 0.0,
        ]);
        $this->assertNotNull($sample->captured_at);
    }

    public function test_trend_averages_by_day(): void
    {
        $this->sampleAt(Carbon::now(), cpu: 2.0, mem: 40.0);
        $this->sampleAt(Carbon::now(), cpu: 4.0, mem: 60.0);
        $this->sampleAt(Carbon::now()->subDays(2), cpu: 1.0, mem: 10.0);

        $trend = app(OpsMetricSampleService::class)->trend(7);

        $this->assertCount(2, $trend);

        $today = collect($trend)->firstWhere('date', Carbon::now()->toDateString());
        $this->assertSame(3.0, $today['cpu_load']);
        $this->assertSame(50.0, $today['memory_used_percent']);

        $twoDaysAgo = collect($trend)->firstWhere('date', Carbon::now()->subDays(2)->toDateString());
        $this->assertSame(1.0, $twoDaysAgo['cpu_load']);
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
