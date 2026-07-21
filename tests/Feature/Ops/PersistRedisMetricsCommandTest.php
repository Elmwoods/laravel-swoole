<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\RedisMetricsService;
use App\Services\Ops\RedisMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PersistRedisMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_persists_one_sample_row(): void
    {
        $this->mock(RedisMetricsService::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andReturn([
                'ops' => 320,
                'clients' => 15,
                'memory' => 3145728, // 3 MB
            ]);
        });
        $this->mock(RedisMonitorService::class, function ($mock): void {
            $mock->shouldReceive('getHitRate')->once()->andReturn(93.5);
        });

        $this->artisan('ops:redis-metrics:persist')->assertSuccessful();

        $this->assertDatabaseCount('ops_redis_metric_samples', 1);
        $this->assertDatabaseHas('ops_redis_metric_samples', [
            'ops' => 320,
            'memory_mb' => 3.0,
            'hit_rate' => 93.5,
        ]);
    }

    public function test_command_is_fault_tolerant_when_collect_throws(): void
    {
        $this->mock(RedisMetricsService::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andThrow(new RuntimeException('redis down'));
        });

        $this->artisan('ops:redis-metrics:persist')->assertSuccessful();

        $this->assertDatabaseCount('ops_redis_metric_samples', 0);
    }

    public function test_demo_option_backfills_samples(): void
    {
        $this->artisan('ops:redis-metrics:persist', ['--demo' => 2])->assertSuccessful();

        $this->assertDatabaseCount('ops_redis_metric_samples', 12);
    }

    public function test_demo_option_is_rejected_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('ops:redis-metrics:persist', ['--demo' => 2])->assertFailed();

        $this->assertDatabaseCount('ops_redis_metric_samples', 0);
    }
}
