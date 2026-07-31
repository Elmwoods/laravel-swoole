<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ops Center 第 23 阶段：告警升级（Escalation）测试。
 *
 * 场景：AlertCenterService::escalateStaleAlerts 定期扫描"长时间未确认的 critical 告警"，
 * 超过 escalation_after_minutes 未 ack 就标记 escalated_at 并重新推送通知；
 * 已 ack、非 critical、太年轻、或刚升级过（未到重推间隔）的告警都不应被升级。
 * 本测试覆盖单级升级语义、重推间隔、禁用开关与 dry-run。
 */
class PhaseTwentyThreeEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 打开升级特性，并设定 30 分钟未确认阈值。
        config()->set('ops.alerts.thresholds.escalation_enabled', true);
        config()->set('ops.alerts.thresholds.escalation_after_minutes', 30);
        // 单级配置：保持 phase-23 的单级升级 + 30 分钟重推间隔语义（多级见 PhaseThirtyFiveMultiEscalationTest）。
        config()->set('ops.alerts.escalation_levels', [
            ['after_minutes' => 30, 'channels' => [], 'reassign_on_call' => false],
        ]);

        // 拦截一切真实外发请求，webhook 重推走 fake。
        Http::preventStrayRequests();
    }

    // 验证核心正例：超龄且未确认的 critical 告警会被标记 escalated_at、写升级事件、并向 webhook 重推。
    public function test_old_unacknowledged_critical_is_escalated_and_renotified(): void
    {
        $this->enableWebhook();
        // 90 分钟 > 30 分钟阈值 → 属于"陈旧未确认"，应被升级。
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

    // 验证年龄门槛：仅 5 分钟龄的 critical 告警（< 30 分钟阈值）不应被升级。
    public function test_young_critical_is_not_escalated(): void
    {
        // 5 分钟 < 30 分钟阈值 → 太年轻，跳过。
        $alert = $this->alert(ageMinutes: 5);

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    // 验证已确认的告警即便超龄也不升级：ack 意味着有人在处理。
    public function test_acknowledged_critical_is_not_escalated(): void
    {
        // status=acknowledged：已有人认领，不再升级。
        $alert = $this->alert(ageMinutes: 90, status: 'acknowledged');

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    // 验证严重级过滤：非 critical（warning）的告警即便超龄也不进入升级流程。
    public function test_non_critical_open_alert_is_not_escalated(): void
    {
        // severity=warning：升级只针对 critical。
        $alert = $this->alert(ageMinutes: 90, severity: 'warning');

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    // 验证重推间隔：刚升级过（5 分钟前）的不再重复升级，而升级已久（40 分钟前 > 30 分钟间隔）的会再次升级。
    public function test_recently_escalated_is_not_re_escalated_but_stale_is(): void
    {
        // recent：5 分钟前刚升级，未到 30 分钟重推间隔 → 不再升级。
        $recent = $this->alert(ageMinutes: 90, escalatedAgoMinutes: 5);
        // stale：40 分钟前升级，已过重推间隔 → 应再次升级。
        $stale = $this->alert(ageMinutes: 90, escalatedAgoMinutes: 40);

        $ids = $this->escalate()->pluck('id')->all();

        $this->assertContains($stale->id, $ids);
        $this->assertNotContains($recent->id, $ids);
    }

    // 验证特性开关：escalation_enabled=false 时即便存在超龄 critical 告警也完全不升级。
    public function test_disabled_config_does_not_escalate(): void
    {
        // 关闭升级开关。
        config()->set('ops.alerts.thresholds.escalation_enabled', false);
        $alert = $this->alert(ageMinutes: 90);

        $this->assertCount(0, $this->escalate());
        $this->assertNull($alert->refresh()->escalated_at);
    }

    // 验证 dry-run：预演能识别出待升级告警，但不落 escalated_at、也不发送任何通知。
    public function test_dry_run_does_not_mark_or_notify(): void
    {
        $this->enableWebhook();
        $alert = $this->alert(ageMinutes: 90);

        // dryRun=true：只返回命中列表，不写库、不推送。
        $escalated = app(AlertCenterService::class)->escalateStaleAlerts(dryRun: true);

        $this->assertCount(1, $escalated);
        $this->assertNull($alert->refresh()->escalated_at);
        Http::assertNothingSent();
    }

    // 触发一次真实升级扫描的快捷方法。
    private function escalate()
    {
        return app(AlertCenterService::class)->escalateStaleAlerts();
    }

    // 造一条告警：ageMinutes 控制创建时间（决定是否超龄），escalatedAgoMinutes 控制上次升级距今多久。
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

        // 用 forceFill 绕过 timestamps 保护，直接回拨 created_at 以模拟告警"已存在了 ageMinutes 分钟"。
        $alert->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $alert;
    }

    // 打开 webhook 通道并 fake 其响应，使升级重推有一个可断言的外发目标。
    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }
}
