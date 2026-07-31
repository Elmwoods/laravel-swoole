<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 35：多级告警升级（Multi-level Escalation）功能测试。
 *
 * 场景：open 告警随存活时长跨越多个阈值逐级升级（L1/L2/L3），
 * 每级可配置通知通道、是否改派当前值班；到达顶级后按间隔重推。
 * 本测试文件覆盖：按存活时长一次跨多级、L2 改派当前值班、
 * 未到首阈值不升级、级别内未到下一阈值/重推间隔则不动作、
 * 顶级到间隔后重推、开关关闭时不升级、以及每级通道限制。
 */
class PhaseThirtyFiveMultiEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 打开升级总开关。
        config()->set('ops.alerts.thresholds.escalation_enabled', true);
        // 定义三级阈值：30/60/120 分钟；L2 触发时改派当前值班人。
        config()->set('ops.alerts.escalation_levels', [
            ['after_minutes' => 30, 'channels' => [], 'reassign_on_call' => false],
            ['after_minutes' => 60, 'channels' => [], 'reassign_on_call' => true],
            ['after_minutes' => 120, 'channels' => [], 'reassign_on_call' => false],
        ]);

        // 关闭所有已配置通知通道，避免测试外发；各用例再按需单独打开 webhook。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        // 阻止任何未被 fake 的真实 HTTP 请求逸出。
        Http::preventStrayRequests();
    }

    // 辅助：启用 webhook 通道并 fake 其响应为 200，用于断言升级时确实外发。
    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    // 辅助：造一条 open 告警。ageMinutes 通过回写 created_at 控制存活时长；
    // level 为当前级别；escalatedAgoMinutes 为距上次升级的分钟数（用于重推间隔判断）。
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
        // 回写 created_at 到过去，模拟告警已存活 ageMinutes 分钟。
        $alert->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $alert;
    }

    // 辅助：执行一次“升级陈旧告警”扫描，返回被升级的告警集合。
    private function escalate()
    {
        return app(AlertCenterService::class)->escalateStaleAlerts();
    }

    /**
     * 验证：存活 70 分钟的告警一次扫描即可跨越 L1、L2 直达 level 2，
     * 写入 escalated 事件并触发 webhook 外发。
     */
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
        // 升级应触发 webhook 外发。
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    /**
     * 验证：升级到 L2（reassign_on_call=true）时，告警被改派给当前值班人 oncall-bob。
     */
    public function test_level2_reassigns_to_current_on_call(): void
    {
        $this->enableWebhook();
        // 造一个当前活跃的值班班次作为改派目标。
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
        // L2 的 reassign_on_call 生效，负责人被改为当前值班人。
        $this->assertSame('oncall-bob', $alert->assigned_to);
    }

    /**
     * 验证：存活未到首个阈值（10 < 30 分钟）时不发生升级，级别保持 0。
     */
    public function test_no_escalation_before_first_threshold(): void
    {
        $alert = $this->alert(ageMinutes: 10, level: 0);
        $this->assertCount(0, $this->escalate());
        $this->assertSame(0, $alert->refresh()->escalation_level);
    }

    /**
     * 验证：目标级别与当前级别一致、且距上次升级太近未达重推间隔时，本轮不动作。
     */
    public function test_stays_at_level_until_next_threshold_or_renotify(): void
    {
        // age 40 → target L1；已在 L1 且刚升级(5min) → 不动作。
        $alert = $this->alert(ageMinutes: 40, level: 1, escalatedAgoMinutes: 5);
        $this->assertCount(0, $this->escalate());
        $this->assertSame(1, $alert->refresh()->escalation_level);
    }

    /**
     * 验证：已处于顶级 L3、且距上次升级已超过该级重推间隔时，重新推送一次通知（级别不变）。
     */
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

    /**
     * 验证：升级总开关关闭时，即便存活很久（200 分钟）也不升级。
     */
    public function test_disabled_does_not_escalate(): void
    {
        // 关闭升级开关，覆盖 setUp 中的默认启用。
        config()->set('ops.alerts.thresholds.escalation_enabled', false);
        $alert = $this->alert(ageMinutes: 200, level: 0);
        $this->assertCount(0, $this->escalate());
        $this->assertSame(0, $alert->refresh()->escalation_level);
    }

    /**
     * 验证：某级配置了 channels=['webhook'] 时，即使同时启用了 telegram，
     * 升级到该级也只发 webhook、不发 telegram（级别通道白名单限制）。
     */
    public function test_per_level_channel_restriction(): void
    {
        // L2 只走 webhook；同时启用 telegram 但级别通道限定 webhook → 只发 webhook。
        config()->set('ops.alerts.escalation_levels', [
            ['after_minutes' => 30, 'channels' => [], 'reassign_on_call' => false],
            ['after_minutes' => 60, 'channels' => ['webhook'], 'reassign_on_call' => false],
        ]);
        // 同时启用 webhook 与 telegram 两个通道并分别 fake，用于验证级别通道白名单只放行 webhook。
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

        // 只应发 webhook，telegram 被级别通道白名单挡下。
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
        Http::assertNotSent(fn ($r): bool => str_contains($r->url(), 'api.telegram.org'));
    }
}
