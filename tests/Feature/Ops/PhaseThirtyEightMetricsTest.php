<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 38：Prometheus 指标导出（Metrics Export）功能测试。
 *
 * 场景：对外暴露 /api/ops/metrics 供 Prometheus 抓取告警/值班/SLA 指标，
 * 通过配置开关启用，并用 token（query 参数或 Bearer 头）做简单鉴权。
 * 本测试文件覆盖：未启用时 404、缺失/错误 token 401、
 * 正确 token 返回 Prometheus 文本格式及关键指标行、Bearer 头鉴权、
 * 以及配置 token 为空时拒绝一切访问（防止空 token 绕过）。
 */
class PhaseThirtyEightMetricsTest extends TestCase
{
    use RefreshDatabase;

    // 辅助：造一条指定级别的 open 告警，用于验证 open 计数与按级别拆分。
    private function openAlert(string $severity): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk', 'severity' => $severity, 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    // 验证：指标导出未启用时，接口返回 404（对外表现为不存在）。
    public function test_disabled_returns_404(): void
    {
        // 关闭指标导出开关。
        config()->set('ops.alerts.metrics.enabled', false);
        $this->getJson('/api/ops/metrics?token=x')->assertStatus(404);
    }

    // 验证：启用后，缺失或错误 token 均返回 401。
    public function test_missing_or_wrong_token_401(): void
    {
        // 启用导出并设置正确 token 为 secret-tok。
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', 'secret-tok');

        $this->get('/api/ops/metrics')->assertStatus(401);
        $this->get('/api/ops/metrics?token=wrong')->assertStatus(401);
    }

    /**
     * 验证：token 正确时返回 Prometheus 纯文本格式（Content-Type text/plain），
     * 并包含关键指标行：open 总数、按级别 open、值班在岗、SLA MTTR。
     */
    public function test_valid_token_returns_prometheus_text(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', 'secret-tok');
        // 造 1 critical + 1 warning，期望 open 总数为 2、critical 维度为 1。
        $this->openAlert('critical');
        $this->openAlert('warning');

        $res = $this->get('/api/ops/metrics?token=secret-tok')->assertOk();
        // Prometheus 抓取要求纯文本格式。
        $this->assertStringContainsString('text/plain', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringContainsString('ops_alerts_open_total 2', $body);
        $this->assertStringContainsString('ops_alerts_open{severity="critical"} 1', $body);
        $this->assertStringContainsString('ops_on_call_present', $body);
        $this->assertStringContainsString('ops_sla_mttr_seconds', $body);
    }

    // 验证：token 也可通过 Authorization: Bearer 头传递并被接受。
    public function test_bearer_token_accepted(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', 'secret-tok');

        // 用 Bearer 头携带正确 token，应放行。
        $this->get('/api/ops/metrics', ['Authorization' => 'Bearer secret-tok'])->assertOk();
    }

    // 验证：配置 token 为空字符串时，即使请求也传空 token 也一律拒绝（避免空 token 绕过鉴权）。
    public function test_empty_config_token_rejects_all(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        // 故意把配置 token 置空，模拟运维未正确设置 token 的情况。
        config()->set('ops.alerts.metrics.token', '');

        $this->get('/api/ops/metrics?token=')->assertStatus(401);
    }
}
