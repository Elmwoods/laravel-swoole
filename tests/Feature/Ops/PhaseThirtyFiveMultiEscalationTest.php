<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtyFiveMultiEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ops.alerts.thresholds.escalation_enabled', true);
        config()->set('ops.alerts.escalation_levels', [
            ['after_minutes' => 30, 'channels' => [], 'reassign_on_call' => false],
            ['after_minutes' => 60, 'channels' => [], 'reassign_on_call' => true],
            ['after_minutes' => 120, 'channels' => [], 'reassign_on_call' => false],
        ]);

        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        Http::preventStrayRequests();
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    private function alert(int $ageMinutes, int $level = 0, ?int $escalatedAgoMinutes = null): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => 'fp-'.uniqid(),
            'source' => 'inspection',
            'severity' => 'critical',
            'title' => '测试告警',
            'message' => 'body',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
            'escalation_level' => $level,
            'escalated_at' => $escalatedAgoMinutes !== null ? now()->subMinutes($escalatedAgoMinutes) : null,
        ]);
        $alert->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $alert;
    }

    private function escalate()
    {
        return app(AlertCenterService::class)->escalateStaleAlerts();
    }

    public function test_age_climbs_through_levels(): void
    {
        $this->enableWebhook();

        // age 70 → 满足 L1(30)+L2(60)，未到 L3(120) → target 2。
        $alert = $this->alert(ageMinutes: 70, level: 0);
        $this->escalate();

        $this->assertSame(2, $alert->refresh()->escalation_level);
        $this->assertDatabaseHas('ops_alert_events', [
            'alert_id' => $alert->id,
            'action' => 'escalated',
        ]);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    public function test_level2_reassigns_to_current_on_call(): void
    {
        $this->enableWebhook();
        OpsOnCallShift::query()->create([
            'assignee' => 'oncall-bob',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'recurrence' => 'once',
            'is_active' => true,
        ]);

        $alert = $this->alert(ageMinutes: 70, level: 1); // 进阶到 L2（reassign_on_call=true）
        $this->escalate();

        $alert->refresh();
        $this->assertSame(2, $alert->escalation_level);
        $this->assertSame('oncall-bob', $alert->assigned_to);
    }

    public function test_no_escalation_before_first_threshold(): void
    {
        $alert = $this->alert(ageMinutes: 10, level: 0);
        $this->assertCount(0, $this->escalate());
        $this->assertSame(0, $alert->refresh()->escalation_level);
    }

    public function test_stays_at_level_until_next_threshold_or_renotify(): void
    {
        // age 40 → target L1；已在 L1 且刚升级(5min) → 不动作。
        $alert = $this->alert(ageMinutes: 40, level: 1, escalatedAgoMinutes: 5);
        $this->assertCount(0, $this->escalate());
        $this->assertSame(1, $alert->refresh()->escalation_level);
    }

    public function test_renotify_at_top_level_after_interval(): void
    {
        $this->enableWebhook();
        // age 200 → target L3(120)；已在 L3，escalated 130min 前 (>= L3 间隔 120) → 重推。
        $alert = $this->alert(ageMinutes: 200, level: 3, escalatedAgoMinutes: 130);
        $escalated = $this->escalate();

        $this->assertCount(1, $escalated);
        $this->assertSame(3, $alert->refresh()->escalation_level);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    public function test_disabled_does_not_escalate(): void
    {
        config()->set('ops.alerts.thresholds.escalation_enabled', false);
        $alert = $this->alert(ageMinutes: 200, level: 0);
        $this->assertCount(0, $this->escalate());
        $this->assertSame(0, $alert->refresh()->escalation_level);
    }

    public function test_per_level_channel_restriction(): void
    {
        // L2 只走 webhook；同时启用 telegram 但级别通道限定 webhook → 只发 webhook。
        config()->set('ops.alerts.escalation_levels', [
            ['after_minutes' => 30, 'channels' => [], 'reassign_on_call' => false],
            ['after_minutes' => 60, 'channels' => ['webhook'], 'reassign_on_call' => false],
        ]);
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '1');
        Http::fake([
            'hooks.example.com/*' => Http::response('', 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->alert(ageMinutes: 70, level: 1); // 进阶到 L2（webhook only）
        $this->escalate();

        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
        Http::assertNotSent(fn ($r): bool => str_contains($r->url(), 'api.telegram.org'));
    }
}
