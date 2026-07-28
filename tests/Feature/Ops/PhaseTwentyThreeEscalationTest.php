<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseTwentyThreeEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ops.alerts.thresholds.escalation_enabled', true);
        config()->set('ops.alerts.thresholds.escalation_after_minutes', 30);
        // 单级配置：保持 phase-23 的单级升级 + 30 分钟重推间隔语义（多级见 PhaseThirtyFiveMultiEscalationTest）。
        config()->set('ops.alerts.escalation_levels', [
            ['after_minutes' => 30, 'channels' => [], 'reassign_on_call' => false],
        ]);

        Http::preventStrayRequests();
    }

    public function test_old_unacknowledged_critical_is_escalated_and_renotified(): void
    {
        $this->enableWebhook();
        $alert = $this->alert(ageMinutes: 90);

        $escalated = $this->escalate();

        $this->assertCount(1, $escalated);
        $this->assertNotNull($alert->refresh()->escalated_at);
        $this->assertDatabaseHas('ops_alert_events', [
            'alert_id' => $alert->id,
            'action' => 'escalated',
            'actor' => 'ops-escalator',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com'));
    }

    public function test_young_critical_is_not_escalated(): void
    {
        $alert = $this->alert(ageMinutes: 5);

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    public function test_acknowledged_critical_is_not_escalated(): void
    {
        $alert = $this->alert(ageMinutes: 90, status: 'acknowledged');

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    public function test_non_critical_open_alert_is_not_escalated(): void
    {
        $alert = $this->alert(ageMinutes: 90, severity: 'warning');

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    public function test_recently_escalated_is_not_re_escalated_but_stale_is(): void
    {
        $recent = $this->alert(ageMinutes: 90, escalatedAgoMinutes: 5);
        $stale = $this->alert(ageMinutes: 90, escalatedAgoMinutes: 40);

        $ids = $this->escalate()->pluck('id')->all();

        $this->assertContains($stale->id, $ids);
        $this->assertNotContains($recent->id, $ids);
    }

    public function test_disabled_config_does_not_escalate(): void
    {
        config()->set('ops.alerts.thresholds.escalation_enabled', false);
        $alert = $this->alert(ageMinutes: 90);

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    public function test_dry_run_does_not_mark_or_notify(): void
    {
        $this->enableWebhook();
        $alert = $this->alert(ageMinutes: 90);

        $escalated = app(AlertCenterService::class)->escalateStaleAlerts(dryRun: true);

        $this->assertCount(1, $escalated);
        $this->assertNull($alert->refresh()->escalated_at);
        Http::assertNothingSent();
    }

    private function escalate()
    {
        return app(AlertCenterService::class)->escalateStaleAlerts();
    }

    private function alert(int $ageMinutes, string $status = 'open', string $severity = 'critical', ?int $escalatedAgoMinutes = null): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => 'fp-'.uniqid(),
            'source' => 'inspection',
            'severity' => $severity,
            'title' => '测试告警',
            'message' => 'body',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => now(),
            'escalated_at' => $escalatedAgoMinutes !== null ? now()->subMinutes($escalatedAgoMinutes) : null,
            // 已升级过的告警其级别为 1（否则进阶逻辑会无视重推间隔直接升级）。
            'escalation_level' => $escalatedAgoMinutes !== null ? 1 : 0,
        ]);

        $alert->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $alert;
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }
}
