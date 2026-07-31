<?php

namespace Tests\Feature\Ops;

use App\DTO\Ops\Log\LogQueryDTO;
use App\Models\AdminAuditLog;
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
 *
 * 补充说明：覆盖 laravel/octane/redis 慢日志/system/docker 各日志源的读取与下载，
 * full 分页模式与 export（下载）模式对 LogQueryDTO 的不同参数装配，
 * 权限门（ops.logs.view）与未登录拦截，路径穿越等非法 source/container 的拒绝，
 * 不可读源不泄露真实路径，以及审计只记录查询参数、绝不写入日志正文的安全约束。
 */
class PhaseThreeLogCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 全部用例默认以拥有日志查看权限的管理员身份运行；个别权限用例会在方法内覆盖为无权限。
        $this->actingAsAdminWithPermissions(['ops.logs.view']);
    }

    /**
     * 验证 Laravel 日志接口正常返回结构化结果。
     * mock LaravelLogService::latest 返回固定一行，断言 code=0、source 与首行内容正确。
     */
    public function test_laravel_log_api_returns_lines(): void
    {
        // mock 日志服务，避免真实读取 storage/logs 文件。
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

    /**
     * 验证未登录访问日志接口被拒 401。先登出再请求。
     */
    public function test_unauthenticated_log_api_is_rejected(): void
    {
        // 主动登出，模拟未认证访问。
        auth('admin')->logout();

        $this->getJson('/api/ops/logs/laravel')
            ->assertStatus(401);
    }

    /**
     * 验证日志接口的权限门：登录但无 ops.logs.view 权限时返回 403。
     */
    public function test_log_api_requires_log_view_permission(): void
    {
        // 覆盖 setUp，改为无任何权限的管理员。
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/logs/laravel')
            ->assertStatus(403)
            ->assertJsonPath('code', 403);
    }

    /**
     * 验证 Octane 日志接口正常返回，断言 source=octane。
     */
    public function test_octane_log_api_returns_lines(): void
    {
        // mock Octane 日志服务返回固定内容。
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

    /**
     * 验证 Redis 慢日志接口返回结构化条目，断言首条 command 字段正确。
     */
    public function test_redis_slow_log_api_returns_entries(): void
    {
        // mock Redis 慢日志服务，返回一条含耗时/命令/客户端的记录。
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

    /**
     * 验证系统日志接口按白名单 source（supervisor）解析，返回 source=system:supervisor。
     */
    public function test_system_log_api_uses_whitelisted_source(): void
    {
        // mock 系统日志服务，模拟白名单内 supervisor 源的读取结果。
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

    /**
     * 验证系统日志 source 参数拒绝路径穿越（../../.env），返回 422 校验错误。
     */
    public function test_log_query_rejects_invalid_source(): void
    {
        // 传入带路径穿越的 source，应被 source 白名单/正则校验拦下。
        $this->getJson('/api/ops/logs/system?source=../../.env')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');
    }

    /**
     * 验证查询参数边界校验：tail 超过上限、from 非日期、keyword 超长各自触发对应字段 422。
     */
    public function test_log_query_validates_tail_time_range_and_keyword_boundaries(): void
    {
        // tail 超过最大值 1000 → 报 tail。
        $this->getJson('/api/ops/logs/laravel?tail=1001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tail');

        // from 不是合法日期 → 报 from。
        $this->getJson('/api/ops/logs/laravel?from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');

        // keyword 长度 121 超过上限 120 → 报 keyword。
        $this->getJson('/api/ops/logs/laravel?keyword='.str_repeat('a', 121))
            ->assertStatus(422)
            ->assertJsonValidationErrors('keyword');
    }

    /**
     * 验证 full（全量分页浏览）模式：装配的 LogQueryDTO 为 mode=full、forExport=false、page=1、lines=200，
     * 且响应返回分页元数据（total/last_page 等）。
     */
    public function test_log_query_accepts_full_mode_without_tail_limit(): void
    {
        // mock 服务并用 Mockery::on 断言传入的 DTO 参数装配符合 full 浏览语义。
        $this->mock(LaravelLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->with(\Mockery::on(fn (LogQueryDTO $dto): bool => $dto->mode === 'full'
                    && $dto->forExport === false
                    && $dto->page === 1
                    && $dto->lines === 200))
                ->andReturn([
                    'source' => 'laravel',
                    'path' => storage_path('logs/laravel.log'),
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['[2026-07-16 10:02:00] local.INFO: latest full page'],
                    'entries' => [
                        [
                            'time' => '2026-07-16 10:02:00',
                            'level' => 'INFO',
                            'summary' => 'latest full page',
                            'content' => '[2026-07-16 10:02:00] local.INFO: latest full page',
                            'lines' => ['[2026-07-16 10:02:00] local.INFO: latest full page'],
                        ],
                    ],
                    'count' => 1500,
                    'entry_count' => 1500,
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 20,
                        'total' => 1500,
                        'last_page' => 75,
                        'has_more' => true,
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/laravel?mode=full&page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.entries.0.summary', 'latest full page')
            ->assertJsonPath('data.pagination.total', 1500)
            ->assertJsonPath('data.pagination.last_page', 75);
    }

    /**
     * 安全：验证不可读的系统日志源返回安全错误，且响应不泄露真实文件路径。
     * 临时把一个白名单源指向不存在/不可读的敏感路径，断言 path=null、exists/readable=false，
     * 且响应体中绝不出现真实路径字符串。
     */
    public function test_system_log_unreadable_source_returns_safe_error_without_path_leak(): void
    {
        // 注入一个指向敏感绝对路径的白名单源，用来验证路径不外泄。
        config()->set('ops.logs.system_sources', [
            'secret' => '/root/very-sensitive.log',
        ]);

        $this->getJson('/api/ops/logs/system?source=secret')
            ->assertOk()
            ->assertJsonPath('data.source', 'system:secret')
            ->assertJsonPath('data.path', null)         // 不回传真实路径
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.readable', false)
            ->assertJsonMissing(['/root/very-sensitive.log']); // 响应体不得包含真实路径
    }

    /**
     * 验证 Docker 日志接口返回结构化分页结果，DTO 装配 lines=50（由 tail 参数决定），断言 source/分页/level。
     */
    public function test_docker_log_api_returns_structured_paginated_result(): void
    {
        // mock Docker 日志服务，并断言按容器名 web + tail=50 调用。
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

    /**
     * 验证 Docker full 浏览模式的翻页：请求 page=2 时 DTO 装配 mode=full、forExport=false、page=2，
     * 响应分页元数据的 current_page/last_page 正确。
     */
    public function test_docker_full_mode_browser_query_stays_paginated(): void
    {
        // mock 并断言以 page=2 的浏览语义调用（非导出）。
        $this->mock(DockerLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->with('web', \Mockery::on(fn (LogQueryDTO $dto): bool => $dto->mode === 'full' && $dto->forExport === false && $dto->page === 2))
                ->andReturn([
                    'source' => 'docker:web',
                    'path' => null,
                    'exists' => true,
                    'readable' => true,
                    'mode' => 'full',
                    'lines' => ['2026-07-06 10:01:00 INFO second page'],
                    'entries' => [
                        [
                            'time' => '2026-07-06 10:01:00',
                            'level' => 'INFO',
                            'summary' => 'INFO second page',
                            'content' => '2026-07-06 10:01:00 INFO second page',
                            'lines' => ['2026-07-06 10:01:00 INFO second page'],
                        ],
                    ],
                    'count' => 120,
                    'entry_count' => 120,
                    'pagination' => [
                        'current_page' => 2,
                        'per_page' => 20,
                        'total' => 120,
                        'last_page' => 6,
                        'has_more' => true,
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/logs/docker?container=web&mode=full&page=2&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.mode', 'full')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 6);
    }

    /**
     * 验证 Docker full 模式的下载走导出 DTO：forExport=true（区别于浏览的 false），
     * 下载响应带 content-disposition 头，且流式内容包含日志正文。
     */
    public function test_docker_full_mode_download_uses_export_dto(): void
    {
        // mock 并断言以 forExport=true 的导出语义调用。
        $this->mock(DockerLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->with('web', \Mockery::on(fn (LogQueryDTO $dto): bool => $dto->mode === 'full'
                    && $dto->forExport === true
                    && $dto->page === 2
                    && $dto->perPage === 20))
                ->andReturn([
                    'source' => 'docker:web',
                    'path' => null,
                    'exists' => true,
                    'readable' => true,
                    'mode' => 'full',
                    'lines' => [
                        '2026-07-06 10:00:00 INFO first page',
                        '2026-07-06 10:01:00 INFO last page',
                    ],
                    'entries' => [
                        [
                            'time' => '2026-07-06 10:00:00',
                            'level' => 'INFO',
                            'summary' => 'INFO first page',
                            'content' => '2026-07-06 10:00:00 INFO first page',
                            'lines' => ['2026-07-06 10:00:00 INFO first page'],
                        ],
                        [
                            'time' => '2026-07-06 10:01:00',
                            'level' => 'INFO',
                            'summary' => 'INFO last page',
                            'content' => '2026-07-06 10:01:00 INFO last page',
                            'lines' => ['2026-07-06 10:01:00 INFO last page'],
                        ],
                    ],
                    'count' => 2,
                    'entry_count' => 2,
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 2,
                        'total' => 2,
                        'last_page' => 1,
                        'has_more' => false,
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $response = $this->get('/api/ops/logs/docker/download?container=web&mode=full&page=2&per_page=20');

        $response->assertOk()
            ->assertHeader('content-disposition');
        // 导出文件流应包含日志正文。
        $this->assertStringContainsString('first page', $response->streamedContent());
    }

    /**
     * 安全：验证日志读取会写审计日志，但审计 payload 只记录查询参数（keyword/tail），
     * 绝不包含日志正文（lines 字段被排除，敏感内容不入库）。
     */
    public function test_log_reads_are_audited_without_log_content(): void
    {
        // mock 返回一行故意含敏感字样的日志，用于验证它不会进入审计。
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

        // 审计记录本身应写入且标记成功。
        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.logs',
            'action' => 'laravel',
            'result' => 'success',
            'status_code' => 200,
        ]);

        // 取出最新审计 payload 逐项核对：只留查询参数，不含日志正文。
        $payload = AdminAuditLog::query()
            ->where('module', 'ops.logs')
            ->where('action', 'laravel')
            ->latest('id')
            ->firstOrFail()
            ->payload;

        $this->assertSame('error', $payload['keyword']);
        $this->assertSame('50', $payload['tail']);
        $this->assertArrayNotHasKey('lines', $payload);            // 不记录日志行
        $this->assertStringNotContainsString('sensitive log body', json_encode($payload, JSON_THROW_ON_ERROR)); // 敏感正文不入库
    }

    /**
     * 验证未登录访问日志下载接口被拒 401。
     */
    public function test_unauthenticated_log_download_is_rejected(): void
    {
        // 登出后再请求下载。
        auth('admin')->logout();

        $this->getJson('/api/ops/logs/laravel/download?tail=50')
            ->assertStatus(401);
    }

    /**
     * 验证日志下载的权限门：无 ops.logs.view 权限时返回 403。
     */
    public function test_log_download_requires_log_view_permission(): void
    {
        // 覆盖为无权限管理员。
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/ops/logs/laravel/download?tail=50')
            ->assertStatus(403)
            ->assertJsonPath('code', 403);
    }

    /**
     * 验证 Laravel 日志下载返回 CSV 附件，且对 =formula 内容做 CSV 注入防护（前置单引号），
     * 同时审计只记查询参数、不含正文。
     */
    public function test_laravel_log_download_returns_attachment_and_audits_without_body(): void
    {
        // mock 返回一条 summary 以 = 开头的记录，用于验证 CSV 公式注入转义。
        $this->mock(LaravelLogService::class, function ($mock): void {
            $mock->shouldReceive('latest')
                ->once()
                ->andReturn([
                    'source' => 'laravel',
                    'path' => storage_path('logs/laravel.log'),
                    'exists' => true,
                    'readable' => true,
                    'lines' => ['2026-07-16 local.ERROR sensitive download body'],
                    'entries' => [
                        [
                            'time' => '2026-07-16 10:00:00',
                            'level' => 'ERROR',
                            'summary' => '=formula payload',
                            'content' => '2026-07-16 local.ERROR sensitive download body',
                            'lines' => ['2026-07-16 local.ERROR sensitive download body'],
                        ],
                    ],
                    'count' => 1,
                    'entry_count' => 1,
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $response = $this->get('/api/ops/logs/laravel/download?keyword=error&tail=50');

        $response->assertOk()
            ->assertHeader('content-disposition');

        $content = $response->streamedContent();
        // CSV 表头。
        $this->assertStringContainsString('source,time,level,summary,content', $content);
        // 公式注入防护：=formula 被前置单引号转义为 '=formula。
        $this->assertStringContainsString("'=formula payload", $content);
        // 下载文件本身应包含日志正文（下载与审计的处理不同：正文可下载但不可入审计）。
        $this->assertStringContainsString('sensitive download body', $content);

        $log = AdminAuditLog::query()
            ->where('module', 'ops.logs')
            ->where('action', 'download')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('success', $log->result);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('error', $log->payload['keyword']);
        $this->assertArrayNotHasKey('lines', $log->payload);          // 下载审计同样不记录日志行
        $this->assertStringNotContainsString('sensitive download body', json_encode($log->payload, JSON_THROW_ON_ERROR)); // 正文不入审计
    }

    /**
     * 验证系统日志下载对非法 source（路径穿越）返回 422，且审计如实记录失败状态（result=failure、422）。
     */
    public function test_system_log_download_rejects_invalid_source_and_audits_real_status(): void
    {
        // 非法 source 触发校验失败。
        $this->getJson('/api/ops/logs/system/download?source=../../.env')
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        // 校验失败也应留下如实的失败审计（真实状态码 422）。
        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.logs',
            'action' => 'download',
            'result' => 'failure',
            'status_code' => 422,
        ]);
    }

    /**
     * 验证 Docker 下载对 container 与 tail 的边界校验：非法容器名（路径穿越）报 container，tail 超上限报 tail。
     */
    public function test_docker_log_download_validates_container_and_tail_boundaries(): void
    {
        // 容器名带路径穿越 → 报 container。
        $this->getJson('/api/ops/logs/docker/download?container=../../docker.sock&tail=50')
            ->assertStatus(422)
            ->assertJsonValidationErrors('container');

        // tail 超过上限 1000 → 报 tail。
        $this->getJson('/api/ops/logs/docker/download?container=web&tail=1001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tail');
    }
}
