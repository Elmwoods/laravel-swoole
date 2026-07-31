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

/**
 * Phase 34「指派通知」测试。
 *
 * 覆盖告警被指派（assign）时是否推送"已指派给 XXX"通知的开关与门控逻辑：
 * - assignment_notify.enabled 总开关；
 * - severity_channels 矩阵（指派通知按 info 级别走，可被矩阵关闭）；
 * - 手动指派会推送、自动指派（on-call 自动分派）不推送。
 * 用 Telegram 通道作为验证载体，通过 Http::fake 断言是否发出请求及其内容。
 */
class PhaseThirtyFourAssignNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 先禁用所有告警通道，保证测试从"干净"状态起步，各用例再按需打开 telegram。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        // 阻止任何未被 Http::fake 覆盖的真实外发请求，防止测试打到真实网络。
        Http::preventStrayRequests();
    }

    // 造一条 open 状态的告警作为被指派对象；severity=warning、fingerprint 唯一避免去重合并。
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

    // 打开 telegram 通道并填齐 bot_token/chat_id，同时 fake 掉 telegram API 返回成功。
    private function enableTelegram(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    // 验证：开启 assignment_notify 后手动指派会向 telegram 推送含"已指派给 alice"文本的通知。
    public function test_manual_assign_pushes_notification_when_enabled(): void
    {
        // 打开指派通知总开关。
        config()->set('ops.alerts.assignment_notify.enabled', true);
        $this->enableTelegram();

        app(AlertCenterService::class)->assign($this->alert(), ['assigned_to' => 'alice']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], '已指派给 alice'));
    }

    // 验证：assignment_notify 关闭时，即便 telegram 可用，指派也不发任何通知。
    public function test_no_push_when_disabled(): void
    {
        // 关闭指派通知总开关。
        config()->set('ops.alerts.assignment_notify.enabled', false);
        $this->enableTelegram();

        app(AlertCenterService::class)->assign($this->alert(), ['assigned_to' => 'alice']);

        Http::assertNothingSent();
    }

    // 验证：即使总开关开着，severity_channels 矩阵把 info→telegram 关掉后，
    // 指派通知（按 info 级别发送）也会被矩阵拦下 → 不发送。
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

    // 验证：新告警在入库时被 on-call 自动分派给值班人，但"自动指派"不触发指派通知（仅手动指派才发）。
    public function test_auto_assign_does_not_push_assignment_notification(): void
    {
        // 同时开启指派通知与 on-call 自动分派，以证明"不发"是由自动路径本身决定，而非通知开关关闭。
        config()->set('ops.alerts.assignment_notify.enabled', true);
        config()->set('ops.alerts.on_call.enabled', true);
        $this->enableTelegram();
        // 一个覆盖当前时间（前后各 1 小时）的启用班次，使新告警能被自动分派给 on-call-a。
        OpsOnCallShift::query()->create([
            'assignee' => 'on-call-a',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $service = app(AlertCenterService::class);
        // storeAlert 是受保护方法，用反射直接调用来模拟一条新告警的入库流程（含自动分派）。
        $method = new \ReflectionMethod($service, 'storeAlert');
        $method->setAccessible(true);
        [$alert] = $method->invoke($service, new AlertDTO(source: 'disk', severity: 'warning', title: 'D', message: 'm'));

        $this->assertSame('on-call-a', $alert->fresh()->assigned_to);
        // 自动指派不发指派通知。
        Http::assertNotSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), '已指派给'));
    }
}
