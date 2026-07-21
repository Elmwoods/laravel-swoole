<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertEvaluation;
use App\Models\OpsInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhaseThirteenTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_trend_requires_permission(): void
    {
        $this->getJson('/api/ops/inspections/trend')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/inspections/trend')->assertStatus(403);
    }

    public function test_alert_trend_requires_permission(): void
    {
        $this->getJson('/api/ops/alerts/trend')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/alerts/trend')->assertStatus(403);
    }

    public function test_inspection_trend_buckets_by_day(): void
    {
        $this->actingAsAdminWithPermissions(['ops.inspections.view']);

        $this->inspectionOn(now(), 'pass');
        $this->inspectionOn(now(), 'fail');
        $this->inspectionOn(now()->subDays(2), 'warn');

        $response = $this->getJson('/api/ops/inspections/trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        $this->assertCount(7, $response);

        $today = collect($response)->firstWhere('date', now()->toDateString());
        $this->assertSame(1, $today['pass']);
        $this->assertSame(1, $today['fail']);
        $this->assertSame(0, $today['warn']);
        $this->assertSame(2, $today['total']);

        $twoDaysAgo = collect($response)->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertSame(1, $twoDaysAgo['warn']);
        $this->assertSame(1, $twoDaysAgo['total']);
    }

    public function test_alert_trend_sums_by_day(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->evaluationOn(now(), detected: 3, autoResolved: 1);
        $this->evaluationOn(now(), detected: 2, autoResolved: 0);
        $this->evaluationOn(now()->subDays(1), detected: 5, autoResolved: 2);

        $response = $this->getJson('/api/ops/alerts/trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        $this->assertCount(7, $response);

        $today = collect($response)->firstWhere('date', now()->toDateString());
        $this->assertSame(2, $today['evaluations']);
        $this->assertSame(5, $today['detected']);
        $this->assertSame(1, $today['auto_resolved']);

        $yesterday = collect($response)->firstWhere('date', now()->subDays(1)->toDateString());
        $this->assertSame(5, $yesterday['detected']);
        $this->assertSame(2, $yesterday['auto_resolved']);
    }

    private function inspectionOn(Carbon $at, string $status): void
    {
        $record = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => $status,
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
            'checks' => [],
            'duration_ms' => 10,
            'started_at' => $at,
            'finished_at' => $at,
        ]);
        $record->forceFill(['created_at' => $at])->save();
    }

    private function evaluationOn(Carbon $at, int $detected, int $autoResolved): void
    {
        $record = OpsAlertEvaluation::query()->create([
            'trigger' => 'schedule',
            'status' => 'success',
            'detected_count' => $detected,
            'auto_resolved_count' => $autoResolved,
            'started_at' => $at,
            'finished_at' => $at,
            'duration_ms' => 20,
            'message' => null,
        ]);
        $record->forceFill(['created_at' => $at])->save();
    }
}
