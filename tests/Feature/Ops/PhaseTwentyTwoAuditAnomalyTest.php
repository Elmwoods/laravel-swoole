<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\OpsAlert;
use App\Services\Ops\AuditAnomalyScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseTwentyTwoAuditAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateFile = sys_get_temp_dir().'/audit-anomaly-'.uniqid().'.json';
        config()->set('ops.alerts.audit_anomaly.state_file', $this->stateFile);
        config()->set('ops.alerts.audit_anomaly.enabled', true);
        config()->set('ops.alerts.audit_anomaly.window_minutes', 10);
        config()->set('ops.alerts.audit_anomaly.failed_login_threshold', 5);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        parent::tearDown();
    }

    public function test_failed_login_burst_raises_critical_alert_and_pushes(): void
    {
        $this->primeCursor();
        $this->enableWebhook();

        for ($i = 0; $i < 5; $i++) {
            $this->auditRow('admin.auth', 'login', 'failure', 'attacker@example.com', '203.0.113.9');
        }

        $result = $this->scan();

        $this->assertSame(1, $result['bursts']);
        $alert = OpsAlert::query()->where('source', 'security_audit')->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('失败登录暴增', (string) $alert->title);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com'));
    }

    public function test_below_threshold_does_not_alert(): void
    {
        $this->primeCursor();

        for ($i = 0; $i < 3; $i++) {
            $this->auditRow('admin.auth', 'login', 'failure', 'user@example.com', '203.0.113.1');
        }

        $this->scan();

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    public function test_sensitive_action_raises_warning_alert(): void
    {
        $this->primeCursor();
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $result = $this->scan();

        $this->assertSame(1, $result['sensitive']);
        $alert = OpsAlert::query()->where('source', 'security_audit')->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString('敏感后台操作', (string) $alert->title);
    }

    public function test_non_sensitive_action_does_not_alert(): void
    {
        $this->primeCursor();
        $this->auditRow('admin.users', 'update', 'success', 'admin@example.com', '10.0.0.1');
        $this->auditRow('ops.octane', 'reload', 'success', 'admin@example.com', '10.0.0.1');

        $this->scan();

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    public function test_cursor_prevents_reprocessing_on_second_scan(): void
    {
        $this->primeCursor();
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $this->scan();
        $this->scan(); // no new rows → nothing re-processed

        $this->assertSame(1, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    public function test_first_run_initializes_cursor_without_alerting(): void
    {
        // 不 primeCursor：造行后首扫应初始化游标、不告警。
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $result = app(AuditAnomalyScanService::class)->scan();

        $this->assertTrue($result['initialized']);
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    public function test_disabled_config_does_not_alert(): void
    {
        config()->set('ops.alerts.audit_anomaly.enabled', false);
        $this->primeCursor();
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $result = $this->scan();

        $this->assertFalse($result['enabled']);
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    public function test_dry_run_does_not_write_alerts_or_advance_cursor(): void
    {
        $this->primeCursor();
        for ($i = 0; $i < 5; $i++) {
            $this->auditRow('admin.auth', 'login', 'failure', 'attacker@example.com', '203.0.113.9');
        }

        app(AuditAnomalyScanService::class)->scan(dryRun: true);
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());

        // 游标未推进：正式扫描仍能检测。
        $result = $this->scan();
        $this->assertSame(1, $result['bursts']);
    }

    private function scan(): array
    {
        return app(AuditAnomalyScanService::class)->scan();
    }

    private function primeCursor(int $lastId = 0): void
    {
        file_put_contents($this->stateFile, json_encode(['last_id' => $lastId]));
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    private function auditRow(string $module, string $action, string $result, ?string $email, ?string $ip): void
    {
        AdminAuditLog::query()->create([
            'admin_email' => $email,
            'module' => $module,
            'action' => $action,
            'result' => $result,
            'status_code' => $result === 'success' ? 200 : 422,
            'ip_address' => $ip,
            'message' => 'test',
        ]);
    }
}
