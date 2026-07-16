<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\DTO\Ops\Log\LogQueryDTO;
use App\Services\Ops\Log\DockerLogService;
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

    public function test_unauthenticated_log_api_is_rejected(): void
    {
        auth('admin')->logout();

        $this->getJson('/api/ops/logs/laravel')
            ->assertStatus(401);
    }

    public function test_log_api_requires_log_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/logs/laravel')
            ->assertStatus(403)
            ->assertJsonPath('code', 403);
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

    public function test_log_query_validates_tail_time_range_and_keyword_boundaries(): void
    {
        $this->getJson('/api/ops/logs/laravel?tail=1001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tail');

        $this->getJson('/api/ops/logs/laravel?from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');

        $this->getJson('/api/ops/logs/laravel?keyword='.str_repeat('a', 121))
            ->assertStatus(422)
            ->assertJsonValidationErrors('keyword');
    }

    public function test_system_log_unreadable_source_returns_safe_error_without_path_leak(): void
    {
        config()->set('ops.logs.system_sources', [
            'secret' => '/root/very-sensitive.log',
        ]);

        $this->getJson('/api/ops/logs/system?source=secret')
            ->assertOk()
            ->assertJsonPath('data.source', 'system:secret')
            ->assertJsonPath('data.path', null)
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.readable', false)
            ->assertJsonMissing(['/root/very-sensitive.log']);
    }

    public function test_docker_log_api_returns_structured_paginated_result(): void
    {
        $this->mock(DockerLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->with('web', \Mockery::on(fn (LogQueryDTO $dto): bool => $dto->lines === 50))
                ->andReturn([
                    'source' => 'docker:web',
                    'path' => null,
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['2026-07-06 10:00:00 INFO booted'],
                    'entries' => [
                        [
                            'time' => '2026-07-06 10:00:00',
                            'level' => 'INFO',
                            'summary' => 'INFO booted',
                            'content' => '2026-07-06 10:00:00 INFO booted',
                            'lines' => ['2026-07-06 10:00:00 INFO booted'],
                        ],
                    ],
                    'count' => 1,
                    'entry_count' => 1,
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 20,
                        'total' => 1,
                        'last_page' => 1,
                        'has_more' => false,
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/docker?container=web&tail=50')
            ->assertOk()
            ->assertJsonPath('data.source', 'docker:web')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.entries.0.level', 'INFO');
    }

    public function test_log_reads_are_audited_without_log_content(): void
    {
        $this->mock(LaravelLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->andReturn([
                    'source' => 'laravel',
                    'path' => null,
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['sensitive log body should not enter audit'],
                    'count' => 1,
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/laravel?keyword=error&tail=50')
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.logs',
            'action' => 'laravel',
            'result' => 'success',
            'status_code' => 200,
        ]);

        $payload = AdminAuditLog::query()
            ->where('module', 'ops.logs')
            ->where('action', 'laravel')
            ->latest('id')
            ->firstOrFail()
            ->payload;

        $this->assertSame('error', $payload['keyword']);
        $this->assertSame('50', $payload['tail']);
        $this->assertArrayNotHasKey('lines', $payload);
        $this->assertStringNotContainsString('sensitive log body', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
