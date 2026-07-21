<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertEvaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OpsAlertEvaluationPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_without_deleting(): void
    {
        $old = $this->evaluationCreatedDaysAgo(60);

        $this->artisan('ops:alerts:prune-evaluations', ['--days' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $old->id]);
    }

    public function test_prune_deletes_records_older_than_retention(): void
    {
        $old = $this->evaluationCreatedDaysAgo(60);
        $fresh = $this->evaluationCreatedDaysAgo(5);

        $this->artisan('ops:alerts:prune-evaluations', ['--days' => 30])
            ->assertSuccessful();

        $this->assertDatabaseMissing('ops_alert_evaluations', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $fresh->id]);
    }

    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->evaluationCreatedDaysAgo(60);

        $this->artisan('ops:alerts:prune-evaluations', ['--days' => 1])
            ->assertFailed();

        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $old->id]);
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
