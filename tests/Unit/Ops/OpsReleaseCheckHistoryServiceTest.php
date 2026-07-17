<?php

namespace Tests\Unit\Ops;

use App\Models\AdminUser;
use App\Models\OpsReleaseCheck;
use App\Services\Ops\OpsReleaseCheckHistoryService;
use App\Services\Ops\OpsReleaseCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OpsReleaseCheckHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_check_history_serializes_summary_and_detail_shapes(): void
    {
        $record = OpsReleaseCheck::query()->create([
            'admin_user_id' => null,
            'admin_email' => 'release@example.com',
            'status' => 'pass',
            'summary' => ['pass' => 1, 'warn' => 0, 'fail' => 0],
            'checks' => [['group' => '环境', 'name' => 'APP_KEY', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']],
            'duration_ms' => 5,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
        $service = app(OpsReleaseCheckHistoryService::class);

        $summary = $service->serializeSummary($record);
        $detail = $service->serializeDetail($record);

        $this->assertArrayNotHasKey('checks', $summary);
        $this->assertSame($record->id, $summary['id']);
        $this->assertSame('pass', $summary['status']);
        $this->assertSame([['group' => '环境', 'name' => 'APP_KEY', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']], $detail['checks']);
    }

    public function test_release_check_history_run_records_failure_without_stack_or_sensitive_values(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Release Runner',
            'email' => 'release-runner@example.com',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        $this->mock(OpsReleaseCheckService::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new RuntimeException('token=unsafe-token password=plain-password failed'));
        });

        $record = app(OpsReleaseCheckHistoryService::class)->runAndRecord($admin);
        $detail = app(OpsReleaseCheckHistoryService::class)->serializeDetail($record);

        $this->assertSame('fail', $record->status);
        $this->assertSame(['pass' => 0, 'warn' => 0, 'fail' => 1], $record->summary);
        $this->assertGreaterThanOrEqual(0, $record->duration_ms);
        $this->assertStringContainsString('[FILTERED]', (string) $record->error_message);
        $this->assertStringNotContainsString('unsafe-token', (string) $record->error_message);
        $this->assertStringNotContainsString('plain-password', (string) $record->error_message);
        $this->assertStringNotContainsString('RuntimeException', json_encode($detail, JSON_THROW_ON_ERROR));
    }
}
