<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 36 - 告警周报（weekly report）测试。
 *
 * 场景：汇总最近 N 天的告警统计、SLA 指标与值班信息生成周报，并可选地推送到 webhook。
 * 本文件覆盖：
 *   - 周报摘要包含 alerts / sla / on_call 三大块及关键字段；
 *   - 启用且有数据时推送成功；
 *   - 功能关闭时跳过(reason=disabled)、无数据且不允许空报时跳过(reason=empty)；
 *   - HTTP 端点返回结构与 ops.alerts.view 权限校验；
 *   - 命令 --dry-run 不实际外发。
 */
class PhaseThirtySixWeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 关闭所有通道，默认不外发。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        Http::preventStrayRequests();
    }

    // 构造一条 open 告警作为周报的数据源；严重度默认 critical。
    private function alert(string $severity = 'critical'): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk', 'severity' => $severity, 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    // 验证：周报摘要结构完整——window_days 透传、alerts.total 计数正确，
    // 且含 by_severity、sla.mtta_avg_seconds、sla.open_aging、on_call.current 等关键块。
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

    // 验证：周报功能启用、webhook 打开且有数据时，sendWeeklyReport 推送成功并命中 webhook。
    public function test_send_when_enabled_and_has_data(): void
    {
        // 打开周报功能与 webhook 通道，并 fake 其响应。
        config()->set('ops.alerts.weekly_report.enabled', true);
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $this->alert(); // 至少一条告警，确保周报非空。

        $result = app(AlertCenterService::class)->sendWeeklyReport(7);
        $this->assertTrue($result['sent']);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    // 验证：周报功能关闭时直接跳过，sent=false 且 reason=disabled。
    public function test_skip_when_disabled(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', false);
        $result = app(AlertCenterService::class)->sendWeeklyReport(7);
        $this->assertFalse($result['sent']);
        $this->assertSame('disabled', $result['reason']);
    }

    // 验证：启用但窗口内无数据、且 send_when_empty=false 时跳过，sent=false 且 reason=empty。
    public function test_skip_when_empty(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', true);
        // 不允许空报 → 无告警数据时应跳过而非发送空周报。
        config()->set('ops.alerts.weekly_report.send_when_empty', false);
        $result = app(AlertCenterService::class)->sendWeeklyReport(7);
        $this->assertFalse($result['sent']);
        $this->assertSame('empty', $result['reason']);
    }

    // 验证：/api/ops/alerts/report 端点返回 200，window_days 透传、alerts.total 计数正确。需 view 权限。
    public function test_report_endpoint(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alert();

        $this->getJson('/api/ops/alerts/report?days=7')
            ->assertOk()
            ->assertJsonPath('data.window_days', 7)
            ->assertJsonPath('data.alerts.total', 1);
    }

    // 验证：无权限的管理员访问周报端点被拒（403）。
    public function test_report_endpoint_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/report')->assertStatus(403);
    }

    // 验证：命令带 --dry-run 时正常退出（码 0）但不实际外发（预演模式）。
    public function test_command_dry_run_does_not_send(): void
    {
        config()->set('ops.alerts.weekly_report.enabled', true);
        $this->alert();
        $this->artisan('ops:alerts:weekly-report --dry-run')->assertExitCode(0);
        Http::assertNothingSent();
    }
}
