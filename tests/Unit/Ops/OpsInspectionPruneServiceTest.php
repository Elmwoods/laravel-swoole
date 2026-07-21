<?php

namespace Tests\Unit\Ops;

use App\Models\OpsInspection;
use App\Services\Ops\OpsInspectionPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OpsInspectionPruneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_retention_days_defaults_when_null(): void
    {
        $this->assertSame(
            OpsInspectionPruneService::DEFAULT_RETENTION_DAYS,
            app(OpsInspectionPruneService::class)->normalizeRetentionDays(null),
        );
    }

    public function test_normalize_retention_days_accepts_valid_value(): void
    {
        $this->assertSame(30, app(OpsInspectionPruneService::class)->normalizeRetentionDays(30));
    }

    public function test_normalize_retention_days_rejects_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OpsInspectionPruneService::class)->normalizeRetentionDays(OpsInspectionPruneService::MIN_RETENTION_DAYS - 1);
    }

    public function test_normalize_retention_days_rejects_above_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OpsInspectionPruneService::class)->normalizeRetentionDays(OpsInspectionPruneService::MAX_RETENTION_DAYS + 1);
    }

    public function test_prune_removes_only_records_older_than_retention(): void
    {
        $old = $this->inspectionCreatedDaysAgo(20);
        $fresh = $this->inspectionCreatedDaysAgo(5);
        $service = app(OpsInspectionPruneService::class);

        $this->assertSame(1, $service->countPrunable(14));
        $this->assertSame(1, $service->prune(14));

        $this->assertDatabaseMissing('ops_inspections', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_inspections', ['id' => $fresh->id]);
    }

    private function inspectionCreatedDaysAgo(int $days): OpsInspection
    {
        $record = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'pass',
            'summary' => ['pass' => 1, 'warn' => 0, 'fail' => 0],
            'checks' => [],
            'duration_ms' => 5,
            'started_at' => now()->subDays($days),
            'finished_at' => now()->subDays($days),
        ]);

        $record->forceFill(['created_at' => now()->subDays($days)])->save();

        return $record;
    }
}
