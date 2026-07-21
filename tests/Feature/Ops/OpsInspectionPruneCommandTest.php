<?php

namespace Tests\Feature\Ops;

use App\Models\OpsInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsInspectionPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_count_without_deleting(): void
    {
        $old = $this->inspectionCreatedDaysAgo(40);

        $this->artisan('ops:inspections:prune', ['--days' => 14, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_inspections', ['id' => $old->id]);
    }

    public function test_prune_deletes_records_older_than_retention(): void
    {
        $old = $this->inspectionCreatedDaysAgo(40);
        $fresh = $this->inspectionCreatedDaysAgo(3);

        $this->artisan('ops:inspections:prune', ['--days' => 14])
            ->assertSuccessful();

        $this->assertDatabaseMissing('ops_inspections', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_inspections', ['id' => $fresh->id]);
    }

    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->inspectionCreatedDaysAgo(40);

        $this->artisan('ops:inspections:prune', ['--days' => 1])
            ->assertFailed();

        $this->assertDatabaseHas('ops_inspections', ['id' => $old->id]);
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
