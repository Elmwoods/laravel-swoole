<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 37 - 告警抖动（flapping）检测测试。
 *
 * 场景：同一告警在短时间内反复 open/resolve（抖动）时，系统应统计窗口内的 reopened
 * 次数，一旦达到阈值就为告警打上 flapping_until 标记；随后通知发送侧（send gate）
 * 会在标记有效期内抑制该告警外发，避免"噪声风暴"。本文件覆盖：
 *   - registerReopen 达阈值后设置 flapping_until 与 flap_count；
 *   - 窗口外的旧 reopened 事件不计入统计；
 *   - send() 对处于抖动期的告警的抑制、过期后放行、以及功能开关关闭时不抑制。
 */
class PhaseThirtySevenFlappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 关闭所有告警通道，确保测试默认不外发；具体用例按需 enableWebhook()。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        // 开启抖动检测并设定参数：30 分钟窗口内 reopened 达 3 次即判定抖动，抑制冷却 30 分钟。
        config()->set('ops.alerts.flapping.enabled', true);
        config()->set('ops.alerts.flapping.window_minutes', 30);
        config()->set('ops.alerts.flapping.threshold', 3);
        config()->set('ops.alerts.flapping.cooldown_minutes', 30);
        // 阻止任何未 fake 的真实 HTTP 请求逃逸出去。
        Http::preventStrayRequests();
    }

    // 打开 webhook 通道并 fake 其响应，供需要验证外发的用例调用。
    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    // 构造一条已入库的 open 告警作为抖动统计的对象。
    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('flap-'.uniqid()),
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    // 验证：窗口内连续 registerReopen 达到阈值（3 次）后，告警被标记为抖动。
    // 前两次不应设置 flapping_until，第三次达阈值时写入未来时间的标记并记 flap_count=3，
    // 同时应落一条 reopened 事件。
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

    // 验证：落在统计窗口（30 分钟）之外的旧 reopened 事件不计入抖动次数。
    // 预置两条 2 小时前的 reopened，再 registerReopen 一次，flap_count 应仅为 1 且不触发抖动标记。
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

    // 验证：send() 发送闸门对处于抖动期内（flapping_until 在未来）的告警予以抑制。
    // 期望返回 reason=flapping，且没有任何请求真正发出。
    public function test_send_gate_suppresses_flapping_alert(): void
    {
        $this->enableWebhook();
        // flapping_until 设为 10 分钟后 → 当前仍在抖动抑制期内。
        $notice = new OpsAlert([
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
            'flapping_until' => now()->addMinutes(10),
        ]);

        $result = app(AlertNotificationService::class)->send($notice);
        $this->assertSame('flapping', $result['webhook']['reason']);
        Http::assertNothingSent();
    }

    // 验证：抖动标记已过期（flapping_until 在过去）时，send() 正常放行外发。
    public function test_send_passes_when_flapping_expired(): void
    {
        $this->enableWebhook();
        // flapping_until 设为 10 分钟前 → 抖动抑制期已结束，应放行。
        $notice = new OpsAlert([
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
            'flapping_until' => now()->subMinutes(10),
        ]);

        $result = app(AlertNotificationService::class)->send($notice);
        $this->assertTrue($result['webhook']['sent']);
    }

    // 验证：抖动检测功能开关关闭时，即使告警带有未过期的 flapping_until 标记也不抑制，正常外发。
    public function test_disabled_flapping_does_not_suppress(): void
    {
        // 关闭抖动检测总开关。
        config()->set('ops.alerts.flapping.enabled', false);
        $this->enableWebhook();
        // 标记仍在未来，但因开关关闭应被忽略。
        $notice = new OpsAlert([
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
            'flapping_until' => now()->addMinutes(10),
        ]);

        $result = app(AlertNotificationService::class)->send($notice);
        $this->assertTrue($result['webhook']['sent']);
    }
}
