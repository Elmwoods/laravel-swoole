<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\MysqlService;
use App\Services\Ops\OctaneControlService;
use App\Services\Ops\RedisService;
use App\Services\Ops\SystemMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 第四阶段告警中心接口测试。
 */
class PhaseFourAlertCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_can_be_listed_and_acknowledged(): void
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1('test-alert'),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => '磁盘使用率过高：/',
            'message' => '挂载点 / 当前使用率 90%',
            'context' => ['target' => '/', 'usage' => 90],
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $this->getJson('/api/ops/alerts?status=open')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.title', '磁盘使用率过高：/');

        $this->postJson("/api/ops/alerts/{$alert->id}/acknowledge", [
            'acknowledged_by' => 'tester',
            'note' => '已处理',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'acknowledged')
            ->assertJsonPath('data.acknowledged_by', 'tester');

        $this->postJson("/api/ops/alerts/{$alert->id}/resolve", [
            'acknowledged_by' => 'tester',
            'note' => '已恢复',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.acknowledge_note', '已恢复');
    }

    public function test_notification_channel_can_be_tested_when_disabled(): void
    {
        config()->set('ops.alerts.telegram.enabled', false);
        config()->set('ops.alerts.mail.enabled', false);

        $this->postJson('/api/ops/alerts/test-notification', [
            'channels' => ['telegram', 'mail'],
            'message' => 'Ops Center test',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.result.telegram.enabled', false)
            ->assertJsonPath('data.result.telegram.sent', false)
            ->assertJsonPath('data.result.mail.enabled', false)
            ->assertJsonPath('data.result.mail.sent', false);
    }

    public function test_notification_status_hides_sensitive_configuration(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'secret-token');
        config()->set('ops.alerts.telegram.chat_id', '123456');
        config()->set('ops.alerts.mail.enabled', true);
        config()->set('ops.alerts.mail.to', ['ops@example.com']);

        $response = $this->getJson('/api/ops/alerts/notification-status')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.telegram.enabled', true)
            ->assertJsonPath('data.telegram.configured', true)
            ->assertJsonPath('data.telegram.missing', [])
            ->assertJsonPath('data.mail.enabled', true)
            ->assertJsonPath('data.mail.configured', true)
            ->assertJsonPath('data.mail.missing', []);

        $response->assertDontSee('secret-token')
            ->assertDontSee('123456')
            ->assertDontSee('ops@example.com');
    }

    public function test_notification_status_reports_missing_configuration(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', '');
        config()->set('ops.alerts.telegram.chat_id', '');
        config()->set('ops.alerts.mail.enabled', false);
        config()->set('ops.alerts.mail.to', []);

        $this->getJson('/api/ops/alerts/notification-status')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.telegram.configured', false)
            ->assertJsonPath('data.telegram.missing', ['bot_token', 'chat_id'])
            ->assertJsonPath('data.mail.enabled', false)
            ->assertJsonPath('data.mail.configured', false)
            ->assertJsonPath('data.mail.missing', ['enabled', 'to']);
    }

    public function test_dashboard_includes_alert_summary(): void
    {
        $this->mock(SystemMonitorService::class, function ($mock): void {
            $mock->shouldReceive('info')
                ->once()
                ->andReturn([
                    'cpu_load' => 0.1,
                    'memory' => ['used_mb' => 64],
                ]);
        });
        $this->mock(RedisService::class, function ($mock): void {
            $mock->shouldReceive('info')
                ->once()
                ->andReturn(['connected' => true]);
        });
        $this->mock(MysqlService::class, function ($mock): void {
            $mock->shouldReceive('info')
                ->once()
                ->andReturn(['connected' => true]);
        });
        $this->mock(OctaneControlService::class, function ($mock): void {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'running' => true,
                    'process_count' => 4,
                    'configured_workers' => 4,
                ]);
        });
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andReturn([
                    'open_total' => 2,
                    'critical' => 1,
                    'warning' => 1,
                    'info' => 0,
                    'sources' => [
                        ['source' => 'disk', 'total' => 1],
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/dashboard')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.alerts.open_total', 2)
            ->assertJsonPath('data.alerts.critical', 1)
            ->assertJsonPath('data.alerts.warning', 1);
    }
}
