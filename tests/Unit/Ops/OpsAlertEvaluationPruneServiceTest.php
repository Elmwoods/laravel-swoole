<?php

namespace Tests\Unit\Ops;

use App\Models\OpsAlertEvaluation;
use App\Services\Ops\OpsAlertEvaluationPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class OpsAlertEvaluationPruneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_retention_days_defaults_when_null(): void
    {
        $this->assertSame(
            OpsAlertEvaluationPruneService::DEFAULT_RETENTION_DAYS,
            app(OpsAlertEvaluationPruneService::class)->normalizeRetentionDays(null),
        );
    }

    public function test_normalize_retention_days_rejects_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(OpsAlertEvaluationPruneService::class)
            ->normalizeRetentionDays(OpsAlertEvaluationPruneService::MIN_RETENTION_DAYS - 1);
    }

    public function test_prune_removes_only_records_older_than_retention(): void
    {
        $old = $this->evaluationCreatedDaysAgo(45);
        $fresh = $this->evaluationCreatedDaysAgo(10);
        $service = app(OpsAlertEvaluationPruneService::class);

        $this->assertSame(1, $service->countPrunable(30));
        $this->assertSame(1, $service->prune(30));

        $this->assertDatabaseMissing('ops_alert_evaluations', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $fresh->id]);
    }

    private function evaluationCreatedDaysAgo(int $days): OpsAlertEvaluation
    {
        $at = Carbon::now()->subDays($days);
        $record = OpsAlertEvaluation::query()->create([
            'trigger' => 'schedule',
            'status' => 'success',
            'detected_count' => 0,
            'auto_resolved_count' => 0,
            'started_at' => $at,
            'finished_at' => $at,
            'duration_ms' => 5,
            'message' => null,
        ]);
        $record->forceFill(['created_at' => $at])->save();

        return $record;
    }
}
