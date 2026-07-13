<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\OctaneControlService;
use App\Services\Ops\QueueMonitorService;
use App\Services\Ops\SupervisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ops Center 第一阶段接口测试
 *
 * 这里使用 mock 隔离 Redis、Supervisor、系统进程等外部依赖，
 * 确认路由、Controller、统一响应结构和 Request 验证能够正常工作。
 */
class PhaseOneOpsCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdminWithPermissions([
            'ops.system.view',
            'ops.supervisor.control',
        ]);
    }

    public function test_octane_status_api_returns_worker_metrics(): void
    {
        $this->mock(OctaneControlService::class, function ($mock): void {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'running' => true,
                    'server' => 'swoole',
                    'configured_workers' => 4,
                    'configured_task_workers' => 2,
                    'master_pid' => 123,
                    'process_count' => 1,
                    'workers' => [],
                    'state_file' => [
                        'path' => storage_path('logs/octane-server-state.json'),
                        'exists' => true,
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/octane/status')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.server', 'swoole')
            ->assertJsonPath('data.configured_workers', 4);
    }

    public function test_queue_summary_api_returns_queue_metrics(): void
    {
        $this->mock(QueueMonitorService::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andReturn([
                    'queues' => [
                        [
                            'name' => 'default',
                            'pending' => 3,
                            'delayed' => 1,
                            'reserved' => 0,
                        ],
                    ],
                    'failed_jobs' => [
                        'count' => 0,
                        'latest' => [],
                    ],
                    'workers' => [
                        'queue_connection' => 'redis',
                        'running' => true,
                        'process_count' => 1,
                        'processes' => [],
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/queue/summary')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.queues.0.name', 'default')
            ->assertJsonPath('data.queues.0.pending', 3);
    }

    public function test_supervisor_process_name_is_validated(): void
    {
        $this->postJson('/api/ops/supervisor/start/bad%20process')
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_supervisor_start_api_accepts_valid_process_name(): void
    {
        $this->mock(SupervisorService::class, function ($mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with('octane')
                ->andReturn([
                    'service' => 'octane',
                    'result' => 'octane: started',
                ]);
        });

        $this->postJson('/api/ops/supervisor/start/octane')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.service', 'octane');
    }
}
