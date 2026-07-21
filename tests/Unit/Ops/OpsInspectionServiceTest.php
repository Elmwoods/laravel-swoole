<?php

namespace Tests\Unit\Ops;

use App\Models\AdminUser;
use App\Models\OpsInspection;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\Log\OpsLogErrorWatcherService;
use App\Services\Ops\OpsInspectionService;
use App\Services\Ops\OpsReleaseCheckService;
use App\Services\Ops\QueueMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OpsInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_service_aggregates_checks_and_persists_record(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Inspection Admin',
            'email' => 'inspection@example.com',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
        $this->mock(OpsReleaseCheckService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andReturn([
                'status' => 'warn',
                'summary' => ['pass' => 1, 'warn' => 1, 'fail' => 0],
                'checks' => [
                    ['group' => '环境', 'name' => 'APP_KEY', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none'],
                    ['group' => '环境', 'name' => 'APP_DEBUG', 'status' => 'warn', 'message' => 'debug on', 'hint' => 'turn off'],
                ],
            ]);
        });
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->with('inspection')->andReturn([
                'detected' => 0,
                'auto_resolved' => 0,
                'checked_at' => now()->toDateTimeString(),
            ]);
            $mock->shouldReceive('resolveInspectionAlert')->once();
        });
        $this->mock(OpsLogErrorWatcherService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn([
                'enabled' => true,
                'scanned' => 1,
                'detected' => 0,
                'events' => [],
            ]);
        });
        $this->mock(QueueMonitorService::class, function ($mock): void {
            $mock->shouldReceive('summary')->once()->andReturn([
                'queues' => [['name' => 'default', 'pending' => 0, 'delayed' => 0, 'reserved' => 0]],
                'failed_jobs' => ['count' => 0, 'latest' => []],
                'workers' => ['running' => true, 'process_count' => 1],
            ]);
        });

        $record = app(OpsInspectionService::class)->run('full', 'manual', $admin);

        $this->assertSame('warn', $record->status);
        $this->assertSame('full', $record->type);
        $this->assertSame('manual', $record->trigger);
        $this->assertSame($admin->id, $record->admin_user_id);
        $this->assertSame(6, array_sum($record->summary));
        $this->assertGreaterThanOrEqual(0, $record->duration_ms);
        $this->assertDatabaseHas('ops_inspections', ['id' => $record->id, 'status' => 'warn']);
    }

    public function test_single_check_failure_does_not_block_other_checks(): void
    {
        $this->mock(OpsReleaseCheckService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('token=unsafe-token failed'));
        });
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andReturn(['detected' => 0, 'auto_resolved' => 0]);
            $mock->shouldReceive('raiseInspectionAlert')->once();
        });
        $this->mock(OpsLogErrorWatcherService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn(['enabled' => true, 'scanned' => 1, 'detected' => 0, 'events' => []]);
        });
        $this->mock(QueueMonitorService::class, function ($mock): void {
            $mock->shouldReceive('summary')->once()->andReturn(['queues' => [], 'failed_jobs' => ['count' => 0], 'workers' => ['running' => false, 'process_count' => 0]]);
        });

        $record = app(OpsInspectionService::class)->run('full', 'manual');
        $detail = app(OpsInspectionService::class)->serializeDetail($record);

        $this->assertSame('fail', $record->status);
        $this->assertSame(1, $record->summary['fail']);
        $this->assertGreaterThan(1, count($record->checks));
        $this->assertStringNotContainsString('unsafe-token', json_encode($detail, JSON_THROW_ON_ERROR));
    }

    public function test_sanitization_redacts_nested_sensitive_values(): void
    {
        $service = app(OpsInspectionService::class);
        $sanitized = $service->sanitize([
            'message' => 'token=unsafe-token password=plain failed',
            'nested' => [
                'secret' => 'unsafe-secret',
                'safe' => 'visible',
            ],
        ]);

        $json = json_encode($sanitized, JSON_THROW_ON_ERROR);

        $this->assertSame('[FILTERED]', $sanitized['nested']['secret']);
        $this->assertStringContainsString('[FILTERED]', $json);
        $this->assertStringContainsString('visible', $json);
        $this->assertStringNotContainsString('unsafe-token', $json);
        $this->assertStringNotContainsString('plain', $json);
    }

    public function test_serializers_omit_checks_from_summary_and_include_checks_in_detail(): void
    {
        $record = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'pass',
            'summary' => ['pass' => 1, 'warn' => 0, 'fail' => 0],
            'checks' => [['group' => '队列/调度', 'name' => 'Queue', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']],
            'duration_ms' => 5,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
        $service = app(OpsInspectionService::class);

        $summary = $service->serializeSummary($record);
        $detail = $service->serializeDetail($record);

        $this->assertArrayNotHasKey('checks', $summary);
        $this->assertSame($record->id, $summary['id']);
        $this->assertSame('Queue', $detail['checks'][0]['name']);
    }
}
