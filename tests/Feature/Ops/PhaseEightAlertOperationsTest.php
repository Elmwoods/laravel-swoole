<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvaluation;
use App\Models\OpsAlertSetting;
use App\Services\Ops\AlertNotificationService;
use App\Services\Ops\Docker\DockerService;
use App\Services\Ops\MysqlService;
use App\Services\Ops\NetworkTrafficService;
use App\Services\Ops\OctaneControlService;
use App\Services\Ops\QueueMonitorService;
use App\Services\Ops\RedisService;
use App\Services\Ops\SupervisorService;
use App\Services\Ops\System\DiskService;
use App\Services\Ops\SystemMonitorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PhaseEightAlertOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdminWithPermissions([
            'ops.alerts.view',
            'ops.alerts.manage',
        ]);
    }

    public function test_evaluation_status_records_success_for_manual_and_cli_runs(): void
    {
        $this->mockHealthySnapshot();

        $this->postJson('/api/ops/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('data.detected', 0);

        $this->getJson('/api/ops/alerts/evaluations/latest')
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.trigger', 'manual')
            ->assertJsonPath('data.detected_count', 0);

        $this->mockHealthySnapshot();

        $this->artisan('ops:alerts:evaluate')
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_alert_evaluations', [
            'trigger' => 'cli',
            'status' => 'success',
            'detected_count' => 0,
        ]);
    }

    public function test_failed_evaluation_records_safe_failure_status(): void
    {
        $this->mock(DiskService::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andThrow(\RuntimeException::class, 'secret /var/www/html/.env failure');
        });

        $this->postJson('/api/ops/alerts/evaluate')
            ->assertStatus(500)
            ->assertDontSee('/var/www/html/.env');

        $this->assertDatabaseHas('ops_alert_evaluations', [
            'trigger' => 'manual',
            'status' => 'failure',
        ]);

        $this->assertStringNotContainsString(
            '/var/www/html/.env',
            OpsAlertEvaluation::query()->latest('id')->firstOrFail()->message ?? '',
        );
    }

    public function test_alert_assignment_and_status_changes_write_timeline(): void
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1('assign-alert'),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => 'Disk warning',
            'message' => 'Disk usage warning',
            'context' => ['target' => '/'],
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $this->postJson("/api/ops/alerts/{$alert->id}/assign", [
            'assigned_to' => 'on-call-a',
            'note' => '交给 A 处理',
        ])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', 'on-call-a')
            ->assertJsonPath('data.timeline.0.action', 'assigned');

        $this->postJson("/api/ops/alerts/{$alert->id}/acknowledge", [
            'acknowledged_by' => 'on-call-a',
            'note' => '已确认',
        ])->assertOk();

        $this->postJson("/api/ops/alerts/{$alert->id}/resolve", [
            'acknowledged_by' => 'on-call-a',
            'note' => '已恢复',
        ])->assertOk();

        $this->assertDatabaseHas('ops_alert_events', [
            'alert_id' => $alert->id,
            'action' => 'assigned',
            'actor' => 'on-call-a',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.alerts',
            'action' => 'assign',
            'result' => 'success',
            'status_code' => 200,
        ]);
        $this->assertDatabaseHas('ops_alert_events', [
            'alert_id' => $alert->id,
            'action' => 'acknowledged',
        ]);
        $this->assertDatabaseHas('ops_alert_events', [
            'alert_id' => $alert->id,
            'action' => 'resolved',
        ]);
    }

    public function test_notification_settings_are_configurable_without_exposing_secrets(): void
    {
        $this->getJson('/api/ops/alerts/settings')
            ->assertOk()
            ->assertJsonPath('data.notification_repeat_minutes', 30)
            ->assertJsonMissingPath('data.telegram.bot_token');

        $this->putJson('/api/ops/alerts/settings', $this->settingsPayload([
            'notification_repeat_minutes' => 10,
            'auto_resolve_grace_minutes' => 3,
            'severity_channels' => [
                'critical' => $this->channelRow(true),
                'warning' => $this->channelRow(true, ['mail' => false]),
                'info' => $this->channelRow(false),
            ],
            'telegram_enabled' => false,
        ]))->assertOk()
            ->assertJsonPath('data.notification_repeat_minutes', 10)
            ->assertJsonPath('data.severity_channels.warning.mail', false);

        $this->assertDatabaseHas('ops_alert_settings', [
            'key' => 'notification_repeat_minutes',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'module' => 'ops.alerts',
            'action' => 'settings_update',
            'result' => 'success',
            'status_code' => 200,
        ]);
        $this->assertSame(10, app(OpsAlertSetting::class)->valueFor('notification_repeat_minutes'));
    }

    public function test_notification_settings_require_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->getJson('/api/ops/alerts/settings')
            ->assertOk();

        $this->putJson('/api/ops/alerts/settings', $this->settingsPayload([
            'notification_repeat_minutes' => 10,
            'auto_resolve_grace_minutes' => 3,
        ]))->assertStatus(403);
    }

    public function test_notification_policy_skips_disabled_severity_channels(): void
    {
        OpsAlertSetting::setValue('severity_channels', [
            'critical' => ['telegram' => true, 'mail' => true],
            'warning' => ['telegram' => false, 'mail' => false],
            'info' => ['telegram' => false, 'mail' => false],
        ]);

        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1('notification-policy'),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => 'Disk warning',
            'message' => 'Disk usage warning',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($alert);

        $this->assertFalse($result['telegram']['sent']);
        $this->assertSame('channel_disabled_by_policy', $result['telegram']['reason']);
        $this->assertFalse($result['mail']['sent']);
        $this->assertSame('channel_disabled_by_policy', $result['mail']['reason']);
    }

    public function test_notification_settings_fall_back_when_settings_table_is_missing(): void
    {
        try {
            Schema::dropIfExists('ops_alert_settings');

            $this->assertSame(
                OpsAlertSetting::defaultSeverityChannels(),
                OpsAlertSetting::value('severity_channels'),
            );

            $alert = OpsAlert::query()->create([
                'fingerprint' => sha1('missing-settings-table'),
                'source' => 'logs:laravel',
                'severity' => 'warning',
                'title' => 'Log warning',
                'message' => 'Missing settings table should not break notification policy',
                'status' => 'open',
                'hit_count' => 1,
                'last_seen_at' => now(),
            ]);

            $result = app(AlertNotificationService::class)->send($alert);

            $this->assertFalse($result['telegram']['sent']);
            $this->assertSame('settings_unavailable', $result['telegram']['reason']);
            $this->assertFalse($result['mail']['sent']);
            $this->assertSame('settings_unavailable', $result['mail']['reason']);
        } finally {
            $this->restoreOpsAlertSettingsTable();
        }
    }

    public function test_notification_failure_logs_are_sanitized(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'secret-bot-token');
        config()->set('ops.alerts.telegram.chat_id', '123456');
        OpsAlertSetting::setValue('telegram_enabled', true);
        OpsAlertSetting::setValue('severity_channels', [
            'critical' => ['telegram' => true, 'mail' => false],
            'warning' => ['telegram' => true, 'mail' => false],
            'info' => ['telegram' => false, 'mail' => false],
        ]);
        Http::fake(function (): void {
            throw new ConnectionException(
                'Failed for https://api.telegram.org/botsecret-bot-token/sendMessage?chat_id=123456&auth_signature=abc123&password=secret',
            );
        });
        Log::spy();

        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1('telegram-sanitized'),
            'source' => 'logs:laravel',
            'severity' => 'warning',
            'title' => 'Telegram warning',
            'message' => 'Notification should sanitize logs',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($alert);

        $this->assertFalse($result['telegram']['sent']);
        $this->assertSame('telegram_exception', $result['telegram']['reason']);
        Log::shouldHaveReceived('warning')
            ->with('Ops alert telegram notification failed', Mockery::on(function (array $context): bool {
                $json = json_encode($context, JSON_THROW_ON_ERROR);

                return ! str_contains($json, 'secret-bot-token')
                    && ! str_contains($json, 'auth_signature=abc123')
                    && ! str_contains($json, 'password=secret')
                    && str_contains($json, '[FILTERED]');
            }));
    }

    private function channelRow(bool $default, array $overrides = []): array
    {
        $row = [];

        foreach ((array) config('ops.alerts.channels') as $channel) {
            $row[$channel] = $default;
        }

        return array_merge($row, $overrides);
    }

    private function settingsPayload(array $overrides = []): array
    {
        $payload = [
            'notification_repeat_minutes' => 10,
            'auto_resolve_enabled' => true,
            'auto_resolve_grace_minutes' => 3,
            'escalation_enabled' => true,
            'escalation_after_minutes' => 30,
            'severity_channels' => [
                'critical' => $this->channelRow(true),
                'warning' => $this->channelRow(true),
                'info' => $this->channelRow(true),
            ],
        ];

        foreach ((array) config('ops.alerts.channels') as $channel) {
            $payload["{$channel}_enabled"] = true;
        }

        return array_merge($payload, $overrides);
    }

    private function mockHealthySnapshot(): void
    {
        $this->mock(DiskService::class, function ($mock): void {
            $mock->shouldReceive('summary')->once()->andReturn(['disks' => []]);
        });
        $this->mock(QueueMonitorService::class, function ($mock): void {
            $mock->shouldReceive('summary')->once()->andReturn(['queues' => [], 'failed_jobs' => ['count' => 0]]);
        });
        $this->mock(DockerService::class, function ($mock): void {
            $mock->shouldReceive('summary')->once()->andReturn(['unhealthy' => 0, 'exited' => 0]);
        });
        $this->mock(NetworkTrafficService::class, function ($mock): void {
            $mock->shouldReceive('getSpeed')->once()->andReturn(['summary' => ['rx_mb_s' => 0, 'tx_mb_s' => 0]]);
        });
        $this->mock(SystemMonitorService::class, function ($mock): void {
            $mock->shouldReceive('info')->once()->andReturn(['cpu_percent' => 10, 'memory' => ['percent' => 30]]);
        });
        $this->mock(RedisService::class, function ($mock): void {
            $mock->shouldReceive('info')->once()->andReturn(['connected' => true, 'used_memory' => '1M']);
        });
        $this->mock(MysqlService::class, function ($mock): void {
            $mock->shouldReceive('info')->once()->andReturn(['connected' => true]);
        });
        $this->mock(OctaneControlService::class, function ($mock): void {
            $mock->shouldReceive('status')->once()->andReturn(['running' => true, 'process_count' => 2]);
        });
        $this->mock(SupervisorService::class, function ($mock): void {
            $mock->shouldReceive('status')->once()->andReturn([
                ['name' => 'octane', 'state' => 'RUNNING'],
            ]);
        });
    }

    private function restoreOpsAlertSettingsTable(): void
    {
        if (Schema::hasTable('ops_alert_settings')) {
            return;
        }

        Schema::create('ops_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->json('value');
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });
    }
}
