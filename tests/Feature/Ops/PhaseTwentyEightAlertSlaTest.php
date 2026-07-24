<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhaseTwentyEightAlertSlaTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $source, string $severity, Carbon $born, string $status = 'resolved'): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => $source.'-'.uniqid(),
            'source' => $source,
            'severity' => $severity,
            'title' => 't',
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => $born,
        ]);
        $alert->created_at = $born;
        $alert->saveQuietly();

        return $alert;
    }

    private function event(OpsAlert $alert, string $action, Carbon $at): void
    {
        OpsAlertEvent::query()->create([
            'alert_id' => $alert->id,
            'action' => $action,
            'actor' => 'x',
            'created_at' => $at,
        ]);
    }

    private function sla(int $days = 30): array
    {
        return app(AlertCenterService::class)->slaSummary($days);
    }

    public function test_mtta_and_mttr_computed_from_events(): void
    {
        $born = now()->subHours(2);
        $alert = $this->alert('disk', 'critical', $born);
        $this->event($alert, 'acknowledged', $born->copy()->addSeconds(300));
        $this->event($alert, 'resolved', $born->copy()->addSeconds(1200));

        $sla = $this->sla();

        $this->assertSame(1, $sla['mtta']['count']);
        $this->assertSame(300, $sla['mtta']['avg_seconds']);
        $this->assertSame(1, $sla['mttr']['count']);
        $this->assertSame(1200, $sla['mttr']['avg_seconds']);
        $this->assertSame(1200, $sla['mttr']['max_seconds']);
    }

    public function test_resolve_without_ack_excluded_from_mtta(): void
    {
        $born = now()->subHour();
        $alert = $this->alert('queue', 'warning', $born);
        $this->event($alert, 'resolved', $born->copy()->addSeconds(600));

        $sla = $this->sla();

        $this->assertSame(0, $sla['mtta']['count']);
        $this->assertSame(1, $sla['mttr']['count']);
        $this->assertSame(600, $sla['mttr']['avg_seconds']);
    }

    public function test_auto_resolve_actions_count_as_resolution(): void
    {
        $born = now()->subHour();
        $alert = $this->alert('security_audit', 'warning', $born);
        $this->event($alert, 'auto_resolved', $born->copy()->addSeconds(120));

        $this->assertSame(1, $this->sla()['mttr']['count']);
    }

    public function test_events_outside_window_are_excluded(): void
    {
        $born = now()->subDays(40);
        $alert = $this->alert('disk', 'info', $born);
        $this->event($alert, 'resolved', $born->copy()->addSeconds(300)); // 40 天前，超出 30 天窗口

        $sla = $this->sla(30);

        $this->assertSame(0, $sla['mttr']['count']);
    }

    public function test_earliest_event_per_alert_is_used(): void
    {
        $born = now()->subHours(3);
        $alert = $this->alert('docker', 'critical', $born);
        $this->event($alert, 'resolved', $born->copy()->addSeconds(600));
        $this->event($alert, 'resolved', $born->copy()->addSeconds(9999)); // 后一次，忽略

        $this->assertSame(600, $this->sla()['mttr']['avg_seconds']);
    }

    public function test_breakdowns_and_trend(): void
    {
        $born = now()->subHours(2);
        $a = $this->alert('disk', 'critical', $born);
        $this->event($a, 'acknowledged', $born->copy()->addSeconds(100));
        $this->event($a, 'resolved', $born->copy()->addSeconds(1000));

        $b = $this->alert('queue', 'warning', $born);
        $this->event($b, 'resolved', $born->copy()->addSeconds(200));

        $sla = $this->sla();

        $bySource = collect($sla['by_source'])->keyBy('source');
        $this->assertSame(1000, $bySource['disk']['mttr_avg_seconds']);
        $this->assertSame(100, $bySource['disk']['mtta_avg_seconds']);
        $this->assertSame(200, $bySource['queue']['mttr_avg_seconds']);
        $this->assertSame(0, $bySource['queue']['mtta_count']);

        $this->assertSame(1000, $sla['by_severity']['critical']['mttr_avg_seconds']);
        $this->assertSame(200, $sla['by_severity']['warning']['mttr_avg_seconds']);

        $this->assertNotEmpty($sla['trend']);
        $this->assertSame(2, collect($sla['trend'])->sum('resolved_count'));
    }

    public function test_open_aging_buckets(): void
    {
        $this->alert('disk', 'warning', now()->subMinutes(30), 'open');
        $this->alert('queue', 'warning', now()->subHours(5), 'open');
        $this->alert('docker', 'critical', now()->subDays(2), 'open');

        $aging = $this->sla()['open_aging'];

        $this->assertSame(1, $aging['under_1h']);
        $this->assertSame(1, $aging['one_to_24h']);
        $this->assertSame(1, $aging['over_24h']);
    }

    public function test_empty_state_is_all_zero(): void
    {
        $sla = $this->sla();

        $this->assertSame(0, $sla['mtta']['count']);
        $this->assertSame(0, $sla['mttr']['avg_seconds']);
        $this->assertSame([], $sla['by_source']);
        $this->assertSame([], $sla['trend']);
        $this->assertSame(0, $sla['open_aging']['under_1h']);
    }

    public function test_endpoint_requires_alerts_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/sla')->assertStatus(403);

        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/sla?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.window_days', 7);
    }
}
