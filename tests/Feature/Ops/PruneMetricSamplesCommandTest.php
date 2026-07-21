<?php

namespace Tests\Feature\Ops;

use App\Models\OpsMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneMetricSamplesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_without_deleting(): void
    {
        $old = $this->sampleDaysAgo(45);

        $this->artisan('ops:metrics:prune', ['--days' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_metric_samples', ['id' => $old->id]);
    }

    public function test_prune_deletes_only_samples_older_than_retention(): void
    {
        $old = $this->sampleDaysAgo(45);
        $fresh = $this->sampleDaysAgo(10);

        $this->artisan('ops:metrics:prune', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('ops_metric_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_metric_samples', ['id' => $fresh->id]);
    }

    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->sampleDaysAgo(45);

        $this->artisan('ops:metrics:prune', ['--days' => 1])->assertFailed();

        $this->assertDatabaseHas('ops_metric_samples', ['id' => $old->id]);
    }

    private function sampleDaysAgo(int $days): OpsMetricSample
    {
        $at = Carbon::now()->subDays($days);
        $sample = OpsMetricSample::query()->create([
            'cpu_load' => 1,
            'load1' => 1,
            'memory_used_percent' => 10,
            'swap_used_percent' => 0,
            'captured_at' => $at,
        ]);
        $sample->forceFill(['captured_at' => $at])->save();

        return $sample;
    }
}
