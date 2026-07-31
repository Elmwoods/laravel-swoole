<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 35：值班仪表盘（On-call Dashboard）功能测试。
 *
 * 场景：值班仪表盘聚合当前值班人、即将到来的班次、指派给“我”的未处理告警统计、
 * 未指派告警数以及 SLA 概览（open_aging、统计窗口天数）。本测试文件覆盖：
 * 完整聚合场景、无值班/无数据时的安全空值返回、以及接口需 ops.alerts.view 权限。
 */
class PhaseThirtyFiveOnCallDashboardTest extends TestCase
{
    use RefreshDatabase;

    // 辅助：造一条告警，可指定级别、状态与负责人（默认 open、未指派），来源固定为 disk。
    private function alert(string $severity, string $status = 'open', ?string $assigned = null): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk',
            'severity' => $severity,
            'title' => 't',
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'assigned_to' => $assigned,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * 验证：仪表盘正确聚合——当前值班 alice、下一个班次 bob、
     * 指派给 alice 的未处理告警数（2 条，含 1 critical）、未指派数（1）、
     * 以及 SLA 概览含 open_aging 且窗口天数按 days=7 返回。
     */
    public function test_dashboard_aggregates_on_call_and_alerts(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        // 当前值班人 alice。
        OpsOnCallShift::query()->create([
            'assignee' => 'alice', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'recurrence' => 'once', 'is_active' => true,
        ]);
        // 未来班次 bob。
        OpsOnCallShift::query()->create([
            'assignee' => 'bob', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
            'recurrence' => 'once', 'is_active' => true,
        ]);

        // 指派给当前值班 alice 的两条 open 告警（1 critical + 1 warning）。
        $this->alert('critical', 'open', 'alice');
        $this->alert('warning', 'open', 'alice');
        $this->alert('info', 'open', null); // 未指派

        $data = $this->getJson('/api/ops/alerts/on-call/dashboard?days=7')->assertOk()->json('data');

        // 当前值班人应为 alice。
        $this->assertSame('alice', $data['current_on_call']);
        // 即将到来的班次只有 bob 一个。
        $this->assertCount(1, $data['upcoming_shifts']);
        $this->assertSame('bob', $data['upcoming_shifts'][0]['assignee']);
        // 指派给当前值班的未处理告警：共 2 条，含 1 条 critical。
        $this->assertSame(2, $data['my_open_alerts']['total']);
        $this->assertSame(1, $data['my_open_alerts']['critical']);
        // 未指派的 open 告警：1 条。
        $this->assertSame(1, $data['unassigned_open']);
        // SLA 概览含 open_aging，且统计窗口按 days=7 返回。
        $this->assertArrayHasKey('open_aging', $data['sla']);
        $this->assertSame(7, $data['sla']['window_days']);
    }

    /**
     * 验证：没有任何值班与告警数据时，仪表盘安全返回空值——
     * 当前值班为 null、我的未处理告警数为 0、即将到来的班次为空数组（不报错）。
     */
    public function test_dashboard_safe_without_on_call(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $data = $this->getJson('/api/ops/alerts/on-call/dashboard')->assertOk()->json('data');

        $this->assertNull($data['current_on_call']);
        $this->assertSame(0, $data['my_open_alerts']['total']);
        $this->assertSame([], $data['upcoming_shifts']);
    }

    // 验证：无任何权限时访问值班仪表盘返回 403。
    public function test_requires_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/on-call/dashboard')->assertStatus(403);
    }
}
