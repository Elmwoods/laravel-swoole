<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\Log\LaravelLogService;
use App\Services\Ops\Log\OctaneLogService;
use App\Services\Ops\Log\RedisLogService;
use App\Services\Ops\Log\SystemLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ops Center 第三阶段日志中心接口测试。
 *
 * 通过 mock 隔离真实日志文件和 Redis 环境，重点验证路由、响应结构、
 * Request 参数校验和系统日志白名单入口。
 */
class PhaseThreeLogCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdminWithPermissions(['ops.logs.view']);
    }

    public function test_laravel_log_api_returns_lines(): void
    {
        $this->mock(LaravelLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->andReturn([
                    'source' => 'laravel',
                    'path' => storage_path('logs/laravel.log'),
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['local.ERROR example'],
                    'count' => 1,
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/laravel?lines=100&keyword=error')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.source', 'laravel')
            ->assertJsonPath('data.lines.0', 'local.ERROR example');
    }

    public function test_octane_log_api_returns_lines(): void
    {
        $this->mock(OctaneLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->andReturn([
                    'source' => 'octane',
                    'path' => storage_path('logs/octane.log'),
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['octane started'],
                    'count' => 1,
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/octane?lines=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.source', 'octane');
    }

    public function test_redis_slow_log_api_returns_entries(): void
    {
        $this->mock(RedisLogService::class, function ($mock): void {
            $mock->shouldReceive('slowLogs')
                ->once()
                ->andReturn([
                    'source' => 'redis',
                    'available' => true,
                    'entries' => [
                        [
                            'id' => 1,
                            'occurred_at' => now()->toDateTimeString(),
                            'duration_ms' => 2.3,
                            'command' => 'GET ops:key',
                            'client' => '127.0.0.1:6379',
                            'client_name' => '',
                        ],
                    ],
                    'count' => 1,
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/redis?lines=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.entries.0.command', 'GET ops:key');
    }

    public function test_system_log_api_uses_whitelisted_source(): void
    {
        $this->mock(SystemLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->andReturn([
                    'source' => 'system:supervisor',
                    'path' => '/tmp/supervisord.log',
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['supervisor started'],
                    'count' => 1,
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/system?source=supervisor&lines=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.source', 'system:supervisor');
    }

    public function test_log_query_rejects_invalid_source(): void
    {
        $this->getJson('/api/ops/logs/system?source=../../.env')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');
    }
}
