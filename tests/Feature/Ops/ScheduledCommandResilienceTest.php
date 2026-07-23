<?php

namespace Tests\Feature\Ops;

use App\Models\OpsInspection;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertChannelHealthService;
use App\Services\Ops\AuditAnomalyScanService;
use App\Services\Ops\Log\OpsLogErrorWatcherService;
use App\Services\Ops\OpsInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 定时 ops 命令在瞬时失败时必须以退出码 0 结束，否则调度器会写 ERROR 日志，
 * 又被 ops:logs:watch-errors 采集成新告警，形成自澎环。
 */
class ScheduledCommandResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_alerts_command_succeeds_even_when_evaluation_throws(): void
    {
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->with('cli')->andThrow(new RuntimeException('collector down'));
        });

        $this->artisan('ops:alerts:evaluate')->assertExitCode(0);
    }

    public function test_inspection_run_command_succeeds_on_fail_result(): void
    {
        $record = new OpsInspection([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'fail',
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
        ]);

        $this->mock(OpsInspectionService::class, function ($mock) use ($record): void {
            $mock->shouldReceive('run')->once()->with('light', 'schedule')->andReturn($record);
        });

        $this->artisan('ops:inspections:run', ['--type' => 'light'])->assertExitCode(0);
    }

    public function test_inspection_run_command_succeeds_when_run_throws(): void
    {
        $this->mock(OpsInspectionService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:inspections:run', ['--type' => 'light'])->assertExitCode(0);
    }

    public function test_inspection_run_command_still_rejects_invalid_type(): void
    {
        $this->artisan('ops:inspections:run', ['--type' => 'bogus'])->assertExitCode(1);
    }

    public function test_watch_errors_command_succeeds_even_when_scan_throws(): void
    {
        $this->mock(OpsLogErrorWatcherService::class, function ($mock): void {
            $mock->shouldReceive('sources')->andReturn(['laravel' => '/tmp/laravel.log']);
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('log unreadable'));
        });

        $this->artisan('ops:logs:watch-errors', ['--once' => true])->assertExitCode(0);
    }

    public function test_audit_anomaly_scan_command_succeeds_even_when_scan_throws(): void
    {
        $this->mock(AuditAnomalyScanService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('audit table down'));
        });

        $this->artisan('ops:audit:scan-anomalies')->assertExitCode(0);
    }

    public function test_escalate_command_succeeds_even_when_escalation_throws(): void
    {
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('escalateStaleAlerts')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:alerts:escalate')->assertExitCode(0);
    }

    public function test_health_check_command_succeeds_even_when_probe_throws(): void
    {
        $this->mock(AlertChannelHealthService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('probe down'));
        });

        $this->artisan('ops:alerts:health-check')->assertExitCode(0);
    }
}
