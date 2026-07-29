<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyEightMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function openAlert(string $severity): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk', 'severity' => $severity, 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_disabled_returns_404(): void
    {
        config()->set('ops.alerts.metrics.enabled', false);
        $this->getJson('/api/ops/metrics?token=x')->assertStatus(404);
    }

    public function test_missing_or_wrong_token_401(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', 'secret-tok');

        $this->get('/api/ops/metrics')->assertStatus(401);
        $this->get('/api/ops/metrics?token=wrong')->assertStatus(401);
    }

    public function test_valid_token_returns_prometheus_text(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', 'secret-tok');
        $this->openAlert('critical');
        $this->openAlert('warning');

        $res = $this->get('/api/ops/metrics?token=secret-tok')->assertOk();
        $this->assertStringContainsString('text/plain', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringContainsString('ops_alerts_open_total 2', $body);
        $this->assertStringContainsString('ops_alerts_open{severity="critical"} 1', $body);
        $this->assertStringContainsString('ops_on_call_present', $body);
        $this->assertStringContainsString('ops_sla_mttr_seconds', $body);
    }

    public function test_bearer_token_accepted(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', 'secret-tok');

        $this->get('/api/ops/metrics', ['Authorization' => 'Bearer secret-tok'])->assertOk();
    }

    public function test_empty_config_token_rejects_all(): void
    {
        config()->set('ops.alerts.metrics.enabled', true);
        config()->set('ops.alerts.metrics.token', '');

        $this->get('/api/ops/metrics?token=')->assertStatus(401);
    }
}
