<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtySevenFlappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        config()->set('ops.alerts.flapping.enabled', true);
        config()->set('ops.alerts.flapping.window_minutes', 30);
        config()->set('ops.alerts.flapping.threshold', 3);
        config()->set('ops.alerts.flapping.cooldown_minutes', 30);
        Http::preventStrayRequests();
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('flap-'.uniqid()),
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_register_reopen_marks_flapping_after_threshold(): void
    {
        $svc = app(AlertCenterService::class);
        $alert = $this->alert();

        $svc->registerReopen($alert);
        $this->assertNull($alert->fresh()->flapping_until); // 1 次
        $svc->registerReopen($alert->fresh());
        $this->assertNull($alert->fresh()->flapping_until); // 2 次
        $svc->registerReopen($alert->fresh());

        $fresh = $alert->fresh();
        $this->assertSame(3, $fresh->flap_count);
        $this->assertNotNull($fresh->flapping_until); // 达阈值
        $this->assertTrue($fresh->flapping_until->isFuture());

        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $alert->id, 'action' => 'reopened']);
    }

    public function test_old_reopens_outside_window_not_counted(): void
    {
        $svc = app(AlertCenterService::class);
        $alert = $this->alert();

        // 两条窗口外的旧 reopened 事件。
        OpsAlertEvent::query()->create([
            'alert_id' => $alert->id, 'action' => 'reopened', 'actor' => 'x', 'created_at' => now()->subHours(2),
        ]);
        OpsAlertEvent::query()->create([
            'alert_id' => $alert->id, 'action' => 'reopened', 'actor' => 'x', 'created_at' => now()->subHours(2),
        ]);

        $svc->registerReopen($alert); // 窗口内仅这 1 条
        $this->assertSame(1, $alert->fresh()->flap_count);
        $this->assertNull($alert->fresh()->flapping_until);
    }

    public function test_send_gate_suppresses_flapping_alert(): void
    {
        $this->enableWebhook();
        $notice = new OpsAlert([
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
            'flapping_until' => now()->addMinutes(10),
        ]);

        $result = app(AlertNotificationService::class)->send($notice);
        $this->assertSame('flapping', $result['webhook']['reason']);
        Http::assertNothingSent();
    }

    public function test_send_passes_when_flapping_expired(): void
    {
        $this->enableWebhook();
        $notice = new OpsAlert([
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
            'flapping_until' => now()->subMinutes(10),
        ]);

        $result = app(AlertNotificationService::class)->send($notice);
        $this->assertTrue($result['webhook']['sent']);
    }

    public function test_disabled_flapping_does_not_suppress(): void
    {
        config()->set('ops.alerts.flapping.enabled', false);
        $this->enableWebhook();
        $notice = new OpsAlert([
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
            'flapping_until' => now()->addMinutes(10),
        ]);

        $result = app(AlertNotificationService::class)->send($notice);
        $this->assertTrue($result['webhook']['sent']);
    }
}
