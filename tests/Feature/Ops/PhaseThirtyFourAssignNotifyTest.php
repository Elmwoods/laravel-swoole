<?php

namespace Tests\Feature\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use App\Models\OpsOnCallShift;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtyFourAssignNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        Http::preventStrayRequests();
    }

    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('ph34-assign-'.uniqid()),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => '磁盘告警',
            'message' => 'msg',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    private function enableTelegram(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    public function test_manual_assign_pushes_notification_when_enabled(): void
    {
        config()->set('ops.alerts.assignment_notify.enabled', true);
        $this->enableTelegram();

        app(AlertCenterService::class)->assign($this->alert(), ['assigned_to' => 'alice']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], '已指派给 alice'));
    }

    public function test_no_push_when_disabled(): void
    {
        config()->set('ops.alerts.assignment_notify.enabled', false);
        $this->enableTelegram();

        app(AlertCenterService::class)->assign($this->alert(), ['assigned_to' => 'alice']);

        Http::assertNothingSent();
    }

    public function test_severity_matrix_can_suppress_assignment_channel(): void
    {
        config()->set('ops.alerts.assignment_notify.enabled', true);
        $this->enableTelegram();
        // 关闭 info→telegram（指派通知 severity=info）。
        OpsAlertSetting::setValue('severity_channels', [
            'critical' => ['telegram' => true],
            'warning' => ['telegram' => true],
            'info' => ['telegram' => false],
        ]);

        app(AlertCenterService::class)->assign($this->alert(), ['assigned_to' => 'alice']);

        Http::assertNothingSent();
    }

    public function test_auto_assign_does_not_push_assignment_notification(): void
    {
        config()->set('ops.alerts.assignment_notify.enabled', true);
        config()->set('ops.alerts.on_call.enabled', true);
        $this->enableTelegram();
        OpsOnCallShift::query()->create([
            'assignee' => 'on-call-a',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $service = app(AlertCenterService::class);
        $method = new \ReflectionMethod($service, 'storeAlert');
        $method->setAccessible(true);
        [$alert] = $method->invoke($service, new AlertDTO(source: 'disk', severity: 'warning', title: 'D', message: 'm'));

        $this->assertSame('on-call-a', $alert->fresh()->assigned_to);
        // 自动指派不发指派通知。
        Http::assertNotSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), '已指派给'));
    }
}
