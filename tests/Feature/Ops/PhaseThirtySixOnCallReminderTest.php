<?php

namespace Tests\Feature\Ops;

use App\Models\OpsOnCallShift;
use App\Services\Ops\OnCallRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtySixOnCallReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        config()->set('ops.alerts.on_call_reminder.enabled', true);
        config()->set('ops.alerts.on_call_reminder.lead_minutes', 15);

        Http::preventStrayRequests();
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    private function shift(int $startsInMinutes, string $recurrence = 'once', ?string $assignee = 'alice'): OpsOnCallShift
    {
        return OpsOnCallShift::query()->create([
            'assignee' => $assignee,
            'starts_at' => now()->addMinutes($startsInMinutes),
            'ends_at' => now()->addMinutes($startsInMinutes + 480),
            'recurrence' => $recurrence,
            'is_active' => true,
        ]);
    }

    private function svc(): OnCallRotationService
    {
        return app(OnCallRotationService::class);
    }

    public function test_upcoming_once_shift_is_reminded_once(): void
    {
        $this->enableWebhook();
        $shift = $this->shift(startsInMinutes: 10);

        $this->assertSame(1, $this->svc()->sendDueReminders());
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
        $this->assertNotNull($shift->refresh()->reminded_at);

        // 再跑不重推。
        $this->assertSame(0, $this->svc()->sendDueReminders());
    }

    public function test_shift_outside_lead_window_not_reminded(): void
    {
        $this->enableWebhook();
        $this->shift(startsInMinutes: 60);

        $this->assertSame(0, $this->svc()->sendDueReminders());
        Http::assertNothingSent();
    }

    public function test_recurring_shift_not_reminded(): void
    {
        $this->enableWebhook();
        $this->shift(startsInMinutes: 10, recurrence: 'daily');

        $this->assertSame(0, $this->svc()->sendDueReminders());
    }

    public function test_disabled_does_not_remind(): void
    {
        config()->set('ops.alerts.on_call_reminder.enabled', false);
        $this->enableWebhook();
        $this->shift(startsInMinutes: 10);

        $this->assertSame(0, $this->svc()->sendDueReminders());
        Http::assertNothingSent();
    }

    public function test_command_runs(): void
    {
        $this->shift(startsInMinutes: 10);
        $this->artisan('ops:on-call:remind')->assertExitCode(0);
    }
}
