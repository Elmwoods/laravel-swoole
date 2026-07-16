<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\Log\OpsLogErrorWatcherService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LogErrorWatcherServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/ops-log-watcher-'.uniqid();
        File::makeDirectory($this->tempDir);

        config()->set('ops.logs.error_watcher.enabled', true);
        config()->set('ops.logs.error_watcher.sources', [
            'laravel' => $this->tempDir.'/laravel.log',
            'build' => $this->tempDir.'/build.log',
        ]);
        config()->set('ops.logs.error_watcher.levels', ['ERROR', 'CRITICAL', 'EMERGENCY']);
        config()->set('ops.logs.error_watcher.context_lines', 2);
        config()->set('ops.logs.error_watcher.max_events_per_run', 10);
        config()->set('ops.logs.error_watcher.state_file', $this->tempDir.'/state.json');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_it_detects_laravel_multiline_errors_and_sanitizes_sensitive_context(): void
    {
        File::put($this->tempDir.'/laravel.log', implode(PHP_EOL, [
            '[2026-07-16 12:00:00] local.ERROR: Log [deprecations] is not defined. token=secret-token',
            '[stacktrace]',
            '#0 /var/www/html/app/Services/Admin/AdminCsvExportService.php(17): fputcsv()',
            '[2026-07-16 12:01:00] local.INFO: healthy',
        ]));

        $result = app(OpsLogErrorWatcherService::class)->scan(['laravel'], dryRun: true, resetOffsets: true);

        $this->assertSame(1, $result['detected']);
        $event = $result['events'][0];
        $this->assertSame('laravel', $event['source']);
        $this->assertSame('ERROR', $event['level']);
        $this->assertStringContainsString('Log [deprecations] is not defined', $event['summary']);
        $this->assertStringContainsString('deprecations', $event['suggestion']);
        $this->assertStringNotContainsString('secret-token', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertSame($event['fingerprint'], app(OpsLogErrorWatcherService::class)->fingerprint($event));
    }

    public function test_it_detects_docker_build_copy_errors(): void
    {
        File::put($this->tempDir.'/build.log', implode(PHP_EOL, [
            '#14 ERROR: failed to calculate checksum of ref abc: "/start-container": not found',
            '#15 ERROR: failed to calculate checksum of ref abc: "/php.ini": not found',
        ]));

        $result = app(OpsLogErrorWatcherService::class)->scan(['build'], dryRun: true, resetOffsets: true);

        $this->assertSame(1, $result['detected']);
        $this->assertSame('build', $result['events'][0]['source']);
        $this->assertStringContainsString('Docker build context', $result['events'][0]['suggestion']);
    }

    public function test_it_uses_offsets_and_detects_log_truncation(): void
    {
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.ERROR: first'.PHP_EOL);

        $service = app(OpsLogErrorWatcherService::class);

        $first = $service->scan(['laravel'], dryRun: true, resetOffsets: true);
        $second = $service->scan(['laravel'], dryRun: true);

        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:01:00] local.ERROR: after truncate'.PHP_EOL);
        $third = $service->scan(['laravel'], dryRun: true);

        $this->assertSame(1, $first['detected']);
        $this->assertSame(0, $second['detected']);
        $this->assertSame(1, $third['detected']);
        $this->assertStringContainsString('after truncate', $third['events'][0]['summary']);
    }
}
