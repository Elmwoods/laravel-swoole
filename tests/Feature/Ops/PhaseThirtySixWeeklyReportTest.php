<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtySixWeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        Http::preventStrayRequests();
    }

    private function alert(string $severity = 'critical'): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk', 'severity' => $severity, 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_summary_has_alerts_sla_oncall_blocks(): void
    {
        $this->alert();
        $report = app(AlertCenterService::class)->weeklyReportSummary(7);

        $this->assertSame(7, $report['window_days']);
        $this->assertSame(1, $report['alerts']['total']);
        $this->assertArrayHasKey('by_severity', $report['alerts']);
        $this->assertArrayHasKey('mtta_avg_seconds', $report['sla']);
        $this->assertArrayHasKey('open_aging', $report['sla']);
        $this->assertArrayHasKey('current', $report['on_call']);
    }

    public function test_send_when_enabled_and_has_data(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', true);
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $this->alert();

        $result = app(AlertCenterService::class)->sendWeeklyReport(7);
        $this->assertTrue($result['sent']);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    public function test_skip_when_disabled(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', false);
        $result = app(AlertCenterService::class)->sendWeeklyReport(7);
        $this->assertFalse($result['sent']);
        $this->assertSame('disabled', $result['reason']);
    }

    public function test_skip_when_empty(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', true);
        config()->set('ops.alerts.weekly_report.send_when_empty', false);
        $result = app(AlertCenterService::class)->sendWeeklyReport(7);
        $this->assertFalse($result['sent']);
        $this->assertSame('empty', $result['reason']);
    }

    public function test_report_endpoint(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alert();

        $this->getJson('/api/ops/alerts/report?days=7')
            ->assertOk()
            ->assertJsonPath('data.window_days', 7)
            ->assertJsonPath('data.alerts.total', 1);
    }

    public function test_report_endpoint_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/report')->assertStatus(403);
    }

    public function test_command_dry_run_does_not_send(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', true);
        $this->alert();
        $this->artisan('ops:alerts:weekly-report --dry-run')->assertExitCode(0);
        Http::assertNothingSent();
    }
}
