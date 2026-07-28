<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyFiveOnCallDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $severity, string $status = 'open', ?string $assigned = null): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk',
            'severity' => $severity,
            'title' => 't',
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'assigned_to' => $assigned,
            'last_seen_at' => now(),
        ]);
    }

    public function test_dashboard_aggregates_on_call_and_alerts(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        // 当前值班人 alice。
        OpsOnCallShift::query()->create([
            'assignee' => 'alice', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'recurrence' => 'once', 'is_active' => true,
        ]);
        // 未来班次 bob。
        OpsOnCallShift::query()->create([
            'assignee' => 'bob', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
            'recurrence' => 'once', 'is_active' => true,
        ]);

        $this->alert('critical', 'open', 'alice');
        $this->alert('warning', 'open', 'alice');
        $this->alert('info', 'open', null); // 未指派

        $data = $this->getJson('/api/ops/alerts/on-call/dashboard?days=7')->assertOk()->json('data');

        $this->assertSame('alice', $data['current_on_call']);
        $this->assertCount(1, $data['upcoming_shifts']);
        $this->assertSame('bob', $data['upcoming_shifts'][0]['assignee']);
        $this->assertSame(2, $data['my_open_alerts']['total']);
        $this->assertSame(1, $data['my_open_alerts']['critical']);
        $this->assertSame(1, $data['unassigned_open']);
        $this->assertArrayHasKey('open_aging', $data['sla']);
        $this->assertSame(7, $data['sla']['window_days']);
    }

    public function test_dashboard_safe_without_on_call(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $data = $this->getJson('/api/ops/alerts/on-call/dashboard')->assertOk()->json('data');

        $this->assertNull($data['current_on_call']);
        $this->assertSame(0, $data['my_open_alerts']['total']);
        $this->assertSame([], $data['upcoming_shifts']);
    }

    public function test_requires_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/on-call/dashboard')->assertStatus(403);
    }
}
