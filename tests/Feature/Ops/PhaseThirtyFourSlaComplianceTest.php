<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PhaseThirtyFourSlaComplianceTest extends TestCase
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
        OpsAlertEvent::query()->create(['alert_id' => $alert->id, 'action' => $action, 'actor' => 'x', 'created_at' => $at]);
    }

    private function sla(int $days = 30): array
    {
        return app(AlertCenterService::class)->slaSummary($days);
    }

    public function test_resolve_compliance_per_severity(): void
    {
        // critical resolve 目标 60min（默认 '60,240,1440'）→ 3600s。
        $born = now()->subHours(5);

        $within = $this->alert('disk', 'critical', $born->copy());
        $this->event($within, 'resolved', $born->copy()->addSeconds(1800)); // 30min 内 → 达标

        $breach = $this->alert('disk', 'critical', $born->copy());
        $this->event($breach, 'resolved', $born->copy()->addSeconds(7200)); // 2h → 超标

        $compliance = $this->sla()['compliance']['resolve']['critical'];

        $this->assertSame(1, $compliance['within']);
        $this->assertSame(2, $compliance['total']);
        $this->assertSame(50, $compliance['rate']);
    }

    public function test_ack_compliance_and_overall(): void
    {
        // warning ack 目标 30min（默认 '10,30,120'）→ 1800s。
        $born = now()->subHours(3);

        $a = $this->alert('queue', 'warning', $born->copy(), 'acknowledged');
        $this->event($a, 'acknowledged', $born->copy()->addSeconds(600)); // 10min → 达标

        $b = $this->alert('queue', 'warning', $born->copy(), 'acknowledged');
        $this->event($b, 'acknowledged', $born->copy()->addSeconds(1200)); // 20min → 达标

        $ack = $this->sla()['compliance']['ack'];

        $this->assertSame(['within' => 2, 'total' => 2, 'rate' => 100], $ack['warning']);
        $this->assertSame(2, $ack['overall']['total']);
        $this->assertSame(100, $ack['overall']['rate']);
    }

    public function test_empty_severity_has_null_rate(): void
    {
        $compliance = $this->sla()['compliance']['resolve'];

        $this->assertSame(['within' => 0, 'total' => 0, 'rate' => null], $compliance['info']);
        $this->assertNull($compliance['overall']['rate']);
    }

    public function test_endpoint_exposes_compliance(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->getJson('/api/ops/alerts/sla?days=30')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['compliance' => ['ack' => ['critical', 'warning', 'info', 'overall'], 'resolve' => ['overall']]],
            ]);
    }
}
