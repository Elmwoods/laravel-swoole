<?php

namespace Tests\Feature\Ops;

use App\Models\OpsOnCallShift;
use App\Services\Ops\OnCallRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 36 - 值班交接提醒（on-call reminder）测试。
 *
 * 场景：在值班班次开始前的提前量（lead window）内，向即将上班的值班人推送一次提醒；
 * 每个班次只提醒一次（幂等），窗口外与周期性(recurring)班次不提醒。本文件覆盖：
 *   - 提前窗口内的一次性班次被提醒一次并写 reminded_at，重复运行不再推；
 *   - 距开始时间超过提前量的班次不提醒；
 *   - recurrence=daily 的周期班次不走此提醒；
 *   - 功能关闭时不提醒；
 *   - artisan 命令可正常执行。
 */
class PhaseThirtySixOnCallReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 关闭所有通道，默认不外发。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        // 开启值班提醒，提前量 15 分钟：班次开始前 15 分钟内才提醒。
        config()->set('ops.alerts.on_call_reminder.enabled', true);
        config()->set('ops.alerts.on_call_reminder.lead_minutes', 15);

        Http::preventStrayRequests();
    }

    // 打开 webhook 通道并 fake 响应，供验证提醒外发的用例使用。
    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    // 构造一个值班班次：startsInMinutes 控制距开始的分钟数（决定是否落在提前窗口内），
    // recurrence 默认 once（一次性），班次时长固定 480 分钟。
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

    // 验证：距开始 10 分钟（在 15 分钟提前窗口内）的一次性班次被提醒一次，写入 reminded_at；
    // 再次运行 sendDueReminders 因幂等不再重复推送（返回 0）。
    public function test_upcoming_once_shift_is_reminded_once(): void
    {
        $this->enableWebhook();
        // 10 < 15，落在提前窗口内。
        $shift = $this->shift(startsInMinutes: 10);

        $this->assertSame(1, $this->svc()->sendDueReminders());
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
        $this->assertNotNull($shift->refresh()->reminded_at);

        // 再跑不重推。
        $this->assertSame(0, $this->svc()->sendDueReminders());
    }

    // 验证：距开始 60 分钟（超出 15 分钟提前窗口）的班次不提醒，无任何请求发出。
    public function test_shift_outside_lead_window_not_reminded(): void
    {
        $this->enableWebhook();
        // 60 > 15，落在提前窗口之外。
        $this->shift(startsInMinutes: 60);

        $this->assertSame(0, $this->svc()->sendDueReminders());
        Http::assertNothingSent();
    }

    // 验证：周期性班次（recurrence=daily）不走一次性交接提醒，即使在提前窗口内也返回 0。
    public function test_recurring_shift_not_reminded(): void
    {
        $this->enableWebhook();
        // 时间在窗口内，但 recurrence=daily → 该提醒只针对 once 班次。
        $this->shift(startsInMinutes: 10, recurrence: 'daily');

        $this->assertSame(0, $this->svc()->sendDueReminders());
    }

    // 验证：提醒功能开关关闭时，即使有窗口内的班次也不提醒。
    public function test_disabled_does_not_remind(): void
    {
        // 关闭提醒总开关。
        config()->set('ops.alerts.on_call_reminder.enabled', false);
        $this->enableWebhook();
        $this->shift(startsInMinutes: 10);

        $this->assertSame(0, $this->svc()->sendDueReminders());
        Http::assertNothingSent();
    }

    // 验证：artisan 命令 ops:on-call:remind 能正常执行并以退出码 0 结束（冒烟测试）。
    public function test_command_runs(): void
    {
        $this->shift(startsInMinutes: 10);
        $this->artisan('ops:on-call:remind')->assertExitCode(0);
    }
}
