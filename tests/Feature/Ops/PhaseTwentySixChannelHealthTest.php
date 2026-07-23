<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\AlertChannelHealthService;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseTwentySixChannelHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        config()->set('ops.alerts.channels', ['telegram', 'webhook']);
        config()->set('ops.alerts.health.enabled', true);
        config()->set('ops.alerts.health.fail_threshold', 2);
        config()->set('ops.alerts.health.probe_channels', []);
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'TESTTOKEN');
        config()->set('ops.alerts.telegram.chat_id', '123');
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://webhook.example.com/hook');
    }

    private function service(): AlertChannelHealthService
    {
        return app(AlertChannelHealthService::class);
    }

    private function fakeHealthy(): void
    {
        Http::fake([
            '*api.telegram.org*' => Http::response(['ok' => true], 200),
            '*webhook.example.com*' => Http::response('', 200),
        ]);
    }

    private function fakeTelegramDown(): void
    {
        Http::fake([
            '*api.telegram.org*' => Http::response(['ok' => false], 500),
            '*webhook.example.com*' => Http::response('', 200),
        ]);
    }

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

    public function test_disabled_skips_everything(): void
    {
        config()->set('ops.alerts.health.enabled', false);
        $this->fakeTelegramDown();

        $result = $this->service()->run();

        $this->assertFalse($result['enabled']);
        $this->assertDatabaseCount('ops_channel_health', 0);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->fakeTelegramDown();

        $result = $this->service()->run(true);

        $this->assertSame(1, $result['failing']);
        $this->assertDatabaseCount('ops_channel_health', 0);
        $this->assertDatabaseMissing('ops_alerts', ['source' => 'channel_health']);
    }

    public function test_probe_channels_restriction(): void
    {
        config()->set('ops.alerts.health.probe_channels', ['webhook']);
        $this->fakeHealthy();

        $result = $this->service()->run();

        $this->assertSame(1, $result['probed']);
        $this->assertDatabaseHas('ops_channel_health', ['channel' => 'webhook']);
        $this->assertDatabaseMissing('ops_channel_health', ['channel' => 'telegram']);
    }

    public function test_unconfigured_channel_is_skipped(): void
    {
        $probe = app(AlertNotificationService::class);

        config()->set('ops.alerts.telegram.bot_token', '');
        $this->assertSame('skipped', $probe->probeChannel('telegram')['checked_via']);

        config()->set('ops.alerts.telegram.enabled', false);
        config()->set('ops.alerts.telegram.bot_token', 'x');
        config()->set('ops.alerts.telegram.chat_id', 'y');
        $this->assertSame('skipped', $probe->probeChannel('telegram')['checked_via']);
    }

    public function test_notification_status_merges_health(): void
    {
        $this->fakeHealthy();
        $this->service()->run();

        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->getJson('/api/ops/alerts/notification-status')
            ->assertOk()
            ->assertJsonPath('data.telegram.health', 'healthy')
            ->assertJsonPath('data.webhook.health', 'healthy');
    }

    public function test_manual_health_check_requires_manage_permission(): void
    {
        $this->fakeHealthy();

        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->postJson('/api/ops/alerts/health-check')->assertStatus(403);

        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->postJson('/api/ops/alerts/health-check')
            ->assertOk()
            ->assertJsonPath('data.summary.enabled', true);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'health_check']);
    }
}
