<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\AlertChannelHealthService;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ops Center 第 26 阶段：告警通道健康自检（Channel Health）测试。
 *
 * 场景：AlertChannelHealthService 定期探活 telegram / webhook 等外发通道，
 * 连续失败达阈值才升警（避免抖动误报），通道恢复后自动 resolve 告警。
 * 本测试覆盖健康/失败/恢复/禁用/dry-run/探测范围限制/未配置跳过，
 * 以及通知状态接口合并健康态与手动自检的权限门禁。
 */
class PhaseTwentySixChannelHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 禁止任何未被 fake 拦截的真实 HTTP 请求逃逸，确保探活完全走 stub。
        Http::preventStrayRequests();

        // 配置两条待自检通道，并把健康检查特性打开。
        config()->set('ops.alerts.channels', ['telegram', 'webhook']);
        config()->set('ops.alerts.health.enabled', true);
        // 连续失败 2 次才升警：验证"抖动不误报、达阈值才告警"的核心语义。
        config()->set('ops.alerts.health.fail_threshold', 2);
        // 空数组表示不限制探测范围，默认探测全部已配置通道。
        config()->set('ops.alerts.health.probe_channels', []);
        // 两条通道都填好凭据/URL，使其"已配置"从而会被真正探活（对照 unconfigured 跳过用例）。
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'TESTTOKEN');
        config()->set('ops.alerts.telegram.chat_id', '123');
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://webhook.example.com/hook');
    }

    // 被测服务：从容器解析，让 config 覆盖生效。
    private function service(): AlertChannelHealthService
    {
        return app(AlertChannelHealthService::class);
    }

    // 两条通道都返回成功：telegram 返回 {ok:true}+200、webhook 返回空体+200。
    private function fakeHealthy(): void
    {
        Http::fake([
            '*api.telegram.org*' => Http::response(['ok' => true], 200),
            '*webhook.example.com*' => Http::response('', 200),
        ]);
    }

    // telegram 探活失败（{ok:false}+500），webhook 仍正常：用于验证单通道失败的独立处理。
    private function fakeTelegramDown(): void
    {
        Http::fake([
            '*api.telegram.org*' => Http::response(['ok' => false], 500),
            '*webhook.example.com*' => Http::response('', 200),
        ]);
    }

    // 验证两条通道均健康时：探活计数正确、健康态落库、连续失败归零、且不产生任何 channel_health 告警。
    public function test_healthy_probe_records_healthy_and_raises_nothing(): void
    {
        $this->fakeHealthy();

        $result = $this->service()->run();

        $this->assertSame(2, $result['probed']);
        $this->assertSame(2, $result['healthy']);
        $this->assertSame(0, $result['raised']);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'telegram', 'status' => 'healthy', 'consecutive_failures' => 0]);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'webhook', 'status' => 'healthy']);
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'channel_health']);
    }

    // 验证抖动抑制：失败通道第 1 次不升警（仅累加连续失败计数），第 2 次达阈值才升警。
    public function test_failing_channel_raises_only_after_threshold(): void
    {
        $this->fakeTelegramDown();

        // 第 1 次失败：未达阈值，不升警。
        $first = $this->service()->run();
        $this->assertSame(0, $first['raised']);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'telegram', 'status' => 'failing', 'consecutive_failures' => 1]);
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'channel_health']);

        // 第 2 次失败：达阈值，升警。
        $second = $this->service()->run();
        $this->assertSame(1, $second['raised']);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'telegram', 'consecutive_failures' => 2]);
        $this->assertDatabaseHas('ops_alerts', ['source' => 'channel_health', 'status' => 'open']);
    }

    // 验证恢复闭环：通道先失败升警，恢复后再自检应自动 resolve 告警、连续失败归零、健康态回落。
    public function test_recovery_resolves_alert(): void
    {
        // 单个有状态 fake：翻转 telegram 连通性（多次 Http::fake 不保证覆盖旧 stub）。
        $state = ['telegram_ok' => false];
        Http::fake([
            '*api.telegram.org*' => function () use (&$state) {
                return Http::response(['ok' => $state['telegram_ok']], $state['telegram_ok'] ? 200 : 500);
            },
            '*webhook.example.com*' => Http::response('', 200),
        ]);

        $this->service()->run();
        $this->service()->run();
        $this->assertDatabaseHas('ops_alerts', ['source' => 'channel_health', 'status' => 'open']);

        // telegram 恢复 → 自检 → 告警自动 resolve + 计数归零。
        $state['telegram_ok'] = true;
        $result = $this->service()->run();

        $this->assertSame(1, $result['resolved']);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'telegram', 'status' => 'healthy', 'consecutive_failures' => 0]);
        $this->assertDatabaseHas('ops_alerts', ['source' => 'channel_health', 'status' => 'resolved']);
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'channel_health', 'status' => 'open']);
    }

    // 验证特性开关：health.enabled=false 时服务直接短路，不探活、不落库、返回 enabled=false。
    public function test_disabled_skips_everything(): void
    {
        // 关闭健康检查开关，即便通道处于失败态也应完全跳过。
        config()->set('ops.alerts.health.enabled', false);
        $this->fakeTelegramDown();

        $result = $this->service()->run();

        $this->assertFalse($result['enabled']);
        $this->assertDatabaseCount('ops_channel_health', 0);
    }

    // 验证 dry-run：预演能算出失败通道数，但不落库 channel_health、不产生告警。
    public function test_dry_run_does_not_persist(): void
    {
        $this->fakeTelegramDown();

        // run(true) = dry-run 模式，只计算不写库。
        $result = $this->service()->run(true);

        $this->assertSame(1, $result['failing']);
        $this->assertDatabaseCount('ops_channel_health', 0);
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'channel_health']);
    }

    // 验证探测范围限制：probe_channels 只列 webhook 时，仅探活并落库 webhook，telegram 被跳过。
    public function test_probe_channels_restriction(): void
    {
        // 白名单只保留 webhook，用于验证按需缩小探测范围。
        config()->set('ops.alerts.health.probe_channels', ['webhook']);
        $this->fakeHealthy();

        $result = $this->service()->run();

        $this->assertSame(1, $result['probed']);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'webhook']);
        $this->assertDatabaseMissing('ops_channel_health', ['channel' => 'telegram']);
    }

    // 验证未配置/被禁用的通道探活时返回 skipped，而不是发起真实请求或误报失败。
    public function test_unconfigured_channel_is_skipped(): void
    {
        $probe = app(AlertNotificationService::class);

        // 缺 bot_token：凭据不全 → 视为未配置 → 探活跳过。
        config()->set('ops.alerts.telegram.bot_token', '');
        $this->assertSame('skipped', $probe->probeChannel('telegram')['checked_via']);

        // 凭据齐全但 enabled=false：通道被显式禁用 → 同样跳过。
        config()->set('ops.alerts.telegram.enabled', false);
        config()->set('ops.alerts.telegram.bot_token', 'x');
        config()->set('ops.alerts.telegram.chat_id', 'y');
        $this->assertSame('skipped', $probe->probeChannel('telegram')['checked_via']);
    }

    // 验证通知状态接口会把最近一次自检得到的健康态合并进各通道返回结构。
    public function test_notification_status_merges_health(): void
    {
        // 先跑一次健康自检落库，接口才有健康态可合并。
        $this->fakeHealthy();
        $this->service()->run();

        // 查看通知状态只需 ops.alerts.view 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->getJson('/api/ops/alerts/notification-status')
            ->assertOk()
            ->assertJsonPath('data.telegram.health', 'healthy')
            ->assertJsonPath('data.webhook.health', 'healthy');
    }

    // 验证手动触发自检需 ops.alerts.manage 权限：仅 view 返回 403，有 manage 才能成功并留下审计日志。
    public function test_manual_health_check_requires_manage_permission(): void
    {
        $this->fakeHealthy();

        // 只有只读权限 → 无权手动触发 → 403。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->postJson('/api/ops/alerts/health-check')->assertStatus(403);

        // 追加 manage 权限 → 成功触发，并写入 module=ops.alerts/action=health_check 审计记录。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->postJson('/api/ops/alerts/health-check')
            ->assertOk()
            ->assertJsonPath('data.summary.enabled', true);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'health_check']);
    }
}
