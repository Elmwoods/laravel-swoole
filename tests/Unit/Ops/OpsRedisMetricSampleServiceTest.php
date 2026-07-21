<?php

namespace Tests\Unit\Ops;

use App\Models\OpsRedisMetricSample;
use App\Services\Ops\OpsRedisMetricSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OpsRedisMetricSampleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_derive_metrics_converts_memory_and_passes_hit_rate(): void
    {
        $metrics = app(OpsRedisMetricSampleService::class)->deriveMetrics([
            'ops' => 500,
            'clients' => 20,
            'memory' => 2097152, // 2 MB
            'hit_rate' => 92.5,
        ]);

        $this->assertSame(500, $metrics['ops']);
        $this->assertSame(20, $metrics['clients']);
        $this->assertSame(2.0, $metrics['memory_mb']);
        $this->assertSame(92.5, $metrics['hit_rate']);
    }

    public function test_derive_metrics_handles_missing_fields(): void
    {
        $metrics = app(OpsRedisMetricSampleService::class)->deriveMetrics([]);

        $this->assertSame(0, $metrics['ops']);
        $this->assertSame(0, $metrics['clients']);
        $this->assertSame(0.0, $metrics['memory_mb']);
        $this->assertSame(0.0, $metrics['hit_rate']);
    }

    public function test_record_persists_a_sample_row(): void
    {
        $sample = app(OpsRedisMetricSampleService::class)->record([
            'ops' => 300,
            'clients' => 12,
            'memory' => 1048576, // 1 MB
            'hit_rate' => 88.0,
        ]);

        $this->assertDatabaseHas('ops_redis_metric_samples', [
            'id' => $sample->id,
            'ops' => 300,
            'memory_mb' => 1.0,
            'hit_rate' => 88.0,
        ]);
    }

    public function test_trend_averages_by_day(): void
    {
        $this->sampleAt(Carbon::now(), ops: 200, hit: 80.0);
        $this->sampleAt(Carbon::now(), ops: 400, hit: 90.0);
        $this->sampleAt(Carbon::now()->subDays(2), ops: 100, hit: 70.0);

        $trend = app(OpsRedisMetricSampleService::class)->trend(7);

        $this->assertCount(2, $trend);
        $today = collect($trend)->firstWhere('date', Carbon::now()->toDateString());
        $this->assertSame(300.0, $today['ops']);
        $this->assertSame(85.0, $today['hit_rate']);
    }

    public function test_seed_demo_backfills_samples_across_days(): void
    {
        $service = app(OpsRedisMetricSampleService::class);

        $count = $service->seedDemo(3);

        $this->assertSame(18, $count);
        $this->assertCount(3, $service->trend(7));
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
