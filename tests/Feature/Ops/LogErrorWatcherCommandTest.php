<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogErrorWatcherCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/ops-log-watcher-command-'.uniqid();
        File::makeDirectory($this->tempDir);

        config()->set('ops.logs.error_watcher.enabled', true);
        config()->set('ops.logs.error_watcher.sources', [
            'laravel' => $this->tempDir.'/laravel.log',
        ]);
        config()->set('ops.logs.error_watcher.state_file', $this->tempDir.'/state.json');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_command_creates_or_updates_alert_for_new_error_events(): void
    {
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.ERROR: fputcsv(): the $escape parameter must be provided'.PHP_EOL);

        $this->artisan('ops:logs:watch-errors', ['--once' => true, '--since-offset-reset' => true])
            ->expectsOutput('Scanned 1 sources, detected 1 new error events')
            ->assertExitCode(0);

        $this->assertDatabaseHas('ops_alerts', [
            'source' => 'logs:laravel',
            'severity' => 'warning',
            'status' => 'open',
            'hit_count' => 1,
        ]);

        $this->artisan('ops:logs:watch-errors', ['--once' => true, '--since-offset-reset' => true])
            ->expectsOutput('Scanned 1 sources, detected 1 new error events')
            ->assertExitCode(0);

        $this->assertSame(1, OpsAlert::query()->count());
        $this->assertSame(2, OpsAlert::query()->firstOrFail()->hit_count);
    }

    public function test_dry_run_prints_diagnostics_without_writing_alerts(): void
    {
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.EMERGENCY: Unable to create configured logger. Log [deprecations] is not defined.'.PHP_EOL);

        $this->artisan('ops:logs:watch-errors', [
            '--once' => true,
            '--dry-run' => true,
            '--since-offset-reset' => true,
        ])
            ->expectsOutputToContain('source=laravel level=EMERGENCY')
            ->expectsOutputToContain('suggestion=')
            ->expectsOutput('Scanned 1 sources, detected 1 new error events')
            ->assertExitCode(0);

        $this->assertDatabaseCount('ops_alerts', 0);
    }

    public function test_command_rejects_non_whitelisted_source(): void
    {
        $this->artisan('ops:logs:watch-errors', [
            '--source' => ['../../.env'],
            '--once' => true,
        ])
            ->expectsOutput('Invalid log source: ../../.env')
            ->assertExitCode(1);
    }

    public function test_command_still_creates_alert_when_notification_settings_table_is_missing(): void
    {
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.ERROR: notification fallback test'.PHP_EOL);

        try {
            Schema::dropIfExists('ops_alert_settings');

            $this->artisan('ops:logs:watch-errors', ['--once' => true, '--since-offset-reset' => true])
                ->expectsOutput('Scanned 1 sources, detected 1 new error events')
                ->assertExitCode(0);

            $alert = OpsAlert::query()->firstOrFail();

            $this->assertSame('logs:laravel', $alert->source);
            $this->assertTrue((bool) data_get($alert->context, 'notification_failed'));
            $this->assertSame('settings_unavailable', data_get($alert->context, 'notification_reason'));
        } finally {
            $this->restoreOpsAlertSettingsTable();
        }
    }

    private function restoreOpsAlertSettingsTable(): void
    {
        if (Schema::hasTable('ops_alert_settings')) {
            return;
        }

        Schema::create('ops_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->json('value');
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });
    }
}
