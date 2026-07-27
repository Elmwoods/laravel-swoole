<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtySlaAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('ops.alerts.channels', ['webhook']);
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hook.example.com/x');
        config()->set('ops.alerts.sla.enabled', true);
        config()->set('ops.alerts.sla.ack_minutes', '10,30,120');
        config()->set('ops.alerts.sla.resolve_minutes', '60,240,1440');
    }

    private function alert(string $severity, Carbon $createdAt, string $status = 'open', string $source = 'disk'): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => $source.'-'.uniqid(),
            'source' => $source,
            'severity' => $severity,
            'title' => 't',
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
        $alert->created_at = $createdAt;
        $alert->saveQuietly();

        return $alert;
    }

    private function scan(bool $dryRun = false): int
    {
        return app(AlertCenterService::class)->scanSlaBreaches($dryRun)->count();
    }

    public function test_ack_breach_raises_sla_alert(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->alert('critical', now()->subMinutes(11)); // ack 目标 10 → 违约

        $this->assertSame(1, $this->scan());
        $this->assertDatabaseHas('ops_alerts', ['source' => 'sla_breach', 'status' => 'open']);
        $this->assertDatabaseHas('ops_alert_events', ['action' => 'sla_breach']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'hook.example.com'));
    }

    public function test_within_target_not_breached(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->alert('critical', now()->subMinutes(9)); // < ack 目标 10

        $this->assertSame(0, $this->scan());
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'sla_breach']);
    }

    public function test_resolve_breach_for_acknowledged_alert(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        // acknowledged 但 open 已久，超 warning resolve 目标 240 分钟。
        $this->alert('warning', now()->subMinutes(300), 'acknowledged');

        $this->assertSame(1, $this->scan());
        $this->assertDatabaseHas('ops_alerts', ['source' => 'sla_breach']);
    }

    public function test_excludes_sla_breach_and_digest_sources(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->alert('critical', now()->subDay(), 'open', 'sla_breach');
        $this->alert('critical', now()->subDay(), 'open', 'digest');

        $this->assertSame(0, $this->scan()); // 防递归 + 不对摘要计 SLA
    }

    public function test_disabled_does_not_scan(): void
    {
        config()->set('ops.alerts.sla.enabled', false);
        $this->alert('critical', now()->subDay());

        $this->assertSame(0, $this->scan());
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'sla_breach']);
    }

    public function test_dry_run_does_not_raise(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $this->alert('critical', now()->subMinutes(20));

        $this->assertSame(1, $this->scan(true));
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'sla_breach']);
        Http::assertNothingSent();
    }

    public function test_breach_auto_resolves_when_original_resolved(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        $alert = $this->alert('critical', now()->subMinutes(20));

        $this->scan();
        $this->assertDatabaseHas('ops_alerts', ['source' => 'sla_breach', 'status' => 'open']);

        // 原告警恢复 → 再扫 → 违约告警自动关闭。
        $alert->forceFill(['status' => 'resolved'])->save();
        $this->scan();

        $breach = OpsAlert::query()->where('source', 'sla_breach')->first();
        $this->assertSame('resolved', $breach->status);
        $this->assertDatabaseHas('ops_alert_events', ['action' => 'sla_breach_cleared']);
    }

    public function test_sla_breach_respects_active_silence(): void
    {
        Http::fake(['*hook.example.com*' => Http::response('', 200)]);
        // 静默命中 sla_breach 源 → 违约告警入库但不外发（phase-29 choke point）。
        OpsAlertSilence::query()->create([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'sources' => ['sla_breach'],
            'severities' => [],
            'is_active' => true,
        ]);
        $this->alert('critical', now()->subMinutes(20));

        $this->scan();

        $this->assertDatabaseHas('ops_alerts', ['source' => 'sla_breach']); // 仍入库
        Http::assertNothingSent(); // 但不外发
    }
}
