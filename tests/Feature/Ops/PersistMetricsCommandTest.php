<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\SystemMetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PersistMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_persists_one_sample_row(): void
    {
        $this->mock(SystemMetricsCollector::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andReturn([
                'cpu' => 1.25,
                'load' => [1.25, 1.0, 0.5],
                'memory' => ['total' => 1000, 'available' => 300],
                'swap' => ['total' => 0, 'free' => 0],
            ]);
        });

        $this->artisan('ops:metrics:persist')->assertSuccessful();

        $this->assertDatabaseCount('ops_metric_samples', 1);
        $this->assertDatabaseHas('ops_metric_samples', [
            'cpu_load' => 1.25,
            'memory_used_percent' => 70.0,
        ]);
    }

    public function test_command_is_fault_tolerant_when_collect_throws(): void
    {
        $this->mock(SystemMetricsCollector::class, function ($mock): void {
            $mock->shouldReceive('collect')->once()->andThrow(new RuntimeException('/proc unreadable'));
        });

        $this->artisan('ops:metrics:persist')->assertSuccessful();

        $this->assertDatabaseCount('ops_metric_samples', 0);
    }
}
