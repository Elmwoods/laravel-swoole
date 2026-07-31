<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\OpsAlert;
use App\Services\Ops\AuditAnomalyScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ops Center 第 22 阶段：审计日志异常扫描（Audit Anomaly）测试。
 *
 * 场景：AuditAnomalyScanService 增量扫描 admin_audit_logs，检测两类异常：
 * (1) 同一 IP 短窗口内失败登录暴增 → 升 critical 告警；
 * (2) 敏感后台操作（如 two_factor_reset）→ 升 warning 告警。
 * 服务用一个 JSON 状态文件保存"上次扫到的 last_id"游标，实现增量、防重复扫描；
 * 首次运行只初始化游标不告警。本测试覆盖阈值、敏感动作过滤、游标推进、禁用与 dry-run。
 */
class PhaseTwentyTwoAuditAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        // 每个用例用独立临时状态文件，避免游标互相污染。
        $this->stateFile = sys_get_temp_dir().'/audit-anomaly-'.uniqid().'.json';
        config()->set('ops.alerts.audit_anomaly.state_file', $this->stateFile);
        config()->set('ops.alerts.audit_anomaly.enabled', true);
        // 10 分钟窗口内统计失败登录。
        config()->set('ops.alerts.audit_anomaly.window_minutes', 10);
        // 同一 IP 失败登录达 5 次即判定为"暴增"。
        config()->set('ops.alerts.audit_anomaly.failed_login_threshold', 5);

        // 拦截真实外发，告警推送走 fake。
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // 清理临时游标文件。
        @unlink($this->stateFile);
        parent::tearDown();
    }

    // 验证失败登录暴增：同一 IP 5 次失败登录（达阈值）→ 升一条 critical 告警并向 webhook 推送。
    public function test_failed_login_burst_raises_critical_alert_and_pushes(): void
    {
        // 先把游标初始化到 0，使后续造的行都算"新增"被扫到。
        $this->primeCursor();
        $this->enableWebhook();

        // 同一攻击者 IP 连续 5 次失败登录，正好触达阈值。
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

    // 验证阈值下限：同一 IP 仅 3 次失败登录（< 5 阈值）不产生任何告警。
    public function test_below_threshold_does_not_alert(): void
    {
        $this->primeCursor();

        // 3 次 < 阈值 5，属正常范围，不应告警。
        for ($i = 0; $i < 3; $i++) {
            $this->auditRow('admin.auth', 'login', 'failure', 'user@example.com', '203.0.113.1');
        }

        $this->scan();

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    // 验证敏感操作检测：一条 two_factor_reset 成功操作即升一条 warning 告警。
    public function test_sensitive_action_raises_warning_alert(): void
    {
        $this->primeCursor();
        // two_factor_reset 属于敏感后台动作，单次即触发 warning。
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $result = $this->scan();

        $this->assertSame(1, $result['sensitive']);
        $alert = OpsAlert::query()->where('source', 'security_audit')->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString('敏感后台操作', (string) $alert->title);
    }

    // 验证敏感动作白名单外的普通操作（update / reload）不产生任何告警。
    public function test_non_sensitive_action_does_not_alert(): void
    {
        $this->primeCursor();
        // update、reload 均非敏感动作，应被忽略。
        $this->auditRow('admin.users', 'update', 'success', 'admin@example.com', '10.0.0.1');
        $this->auditRow('ops.octane', 'reload', 'success', 'admin@example.com', '10.0.0.1');

        $this->scan();

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    // 验证增量游标去重：同一条敏感操作被扫两次，第二次因无新行不会重复告警（仍只有 1 条告警）。
    public function test_cursor_prevents_reprocessing_on_second_scan(): void
    {
        $this->primeCursor();
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $this->scan();
        $this->scan(); // no new rows → nothing re-processed

        $this->assertSame(1, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    // 验证冷启动：无游标文件时首扫只初始化游标（initialized=true），不对历史存量数据告警。
    public function test_first_run_initializes_cursor_without_alerting(): void
    {
        // 不 primeCursor：造行后首扫应初始化游标、不告警。
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $result = app(AuditAnomalyScanService::class)->scan();

        $this->assertTrue($result['initialized']);
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    // 验证特性开关：audit_anomaly.enabled=false 时扫描短路返回 enabled=false，不产生告警。
    public function test_disabled_config_does_not_alert(): void
    {
        // 关闭异常扫描开关。
        config()->set('ops.alerts.audit_anomaly.enabled', false);
        $this->primeCursor();
        $this->auditRow('admin.users', 'two_factor_reset', 'success', 'admin@example.com', '10.0.0.1');

        $result = $this->scan();

        $this->assertFalse($result['enabled']);
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());
    }

    // 验证 dry-run：预演不写告警也不推进游标，因此紧接着的正式扫描仍能检测到同一批暴增。
    public function test_dry_run_does_not_write_alerts_or_advance_cursor(): void
    {
        $this->primeCursor();
        for ($i = 0; $i < 5; $i++) {
            $this->auditRow('admin.auth', 'login', 'failure', 'attacker@example.com', '203.0.113.9');
        }

        // dryRun=true：不落告警、不推进游标。
        app(AuditAnomalyScanService::class)->scan(dryRun: true);
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_audit')->count());

        // 游标未推进：正式扫描仍能检测。
        $result = $this->scan();
        $this->assertSame(1, $result['bursts']);
    }

    // 触发一次正式扫描的快捷方法。
    private function scan(): array
    {
        return app(AuditAnomalyScanService::class)->scan();
    }

    // 预置游标文件到指定 last_id（默认 0），使随后造的审计行都被当作"新增"处理，从而跳过冷启动初始化分支。
    private function primeCursor(int $lastId = 0): void
    {
        file_put_contents($this->stateFile, json_encode(['last_id' => $lastId]));
    }

    // 打开 webhook 通道并 fake 响应，让 critical 告警的外发推送有可断言的目标。
    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    // 造一条审计日志行：result=success 记 200、否则记 422，用于模拟成功/失败动作。
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
