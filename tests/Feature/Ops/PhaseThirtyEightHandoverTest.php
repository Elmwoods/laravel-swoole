<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use App\Models\OpsShiftHandover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 38：值班交接记录（Shift Handover）功能测试。
 *
 * 场景：值班人换班时创建一条交接记录，快照当前未处理告警数量，
 * 并支持 from/to 的智能默认值——to 缺省取当前值班人、from 缺省取上一条交接的接手人。
 * 本测试文件覆盖：显式 from/to 创建并快照 open_alert_count、to 默认当前值班、
 * from 默认上一条交接的 to、缺少可推断的 to 时 422、列表按最新在前、
 * 以及创建需要 ops.alerts.manage 权限。
 */
class PhaseThirtyEightHandoverTest extends TestCase
{
    use RefreshDatabase;

    // 辅助：造一条 open 状态的告警，用于快照未处理告警数量。
    private function openAlert(string $severity = 'warning'): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($severity.uniqid()),
            'source' => 'disk', 'severity' => $severity, 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    /**
     * 验证：显式传入 from/to 创建交接时，记录接手人正确，
     * 且 open_alert_count 快照为当前 open 告警数（此处 2 条），并写入审计日志。
     */
    public function test_create_with_explicit_from_to_snapshots_open_count(): void
    {
        // 需要 view + manage：创建交接属写操作，要求 manage 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        // 造两条 open 告警，期望被快照为 open_alert_count=2。
        $this->openAlert();
        $this->openAlert();

        $this->postJson('/api/ops/alerts/handovers', ['from_assignee' => 'alice', 'to_assignee' => 'bob', 'note' => '注意磁盘'])
            ->assertOk()
            ->assertJsonPath('data.handover.to_assignee', 'bob')
            ->assertJsonPath('data.handover.open_alert_count', 2);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'handover_create', 'result' => 'success']);
    }

    /**
     * 验证：未传 to_assignee 时，接手人默认取当前正在值班的人（此处 oncall-carol）。
     */
    public function test_to_defaults_to_current_on_call(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        // 造一个当前时间落在班次内（前一小时到后一小时）的活跃值班，作为默认接手人。
        OpsOnCallShift::query()->create([
            'assignee' => 'oncall-carol', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'recurrence' => 'once', 'is_active' => true,
        ]);

        // 只传 from，to 应自动回填为当前值班人。
        $this->postJson('/api/ops/alerts/handovers', ['from_assignee' => 'alice'])
            ->assertOk()
            ->assertJsonPath('data.handover.to_assignee', 'oncall-carol');
    }

    /**
     * 验证：未传 from_assignee 时，交出人默认取上一条交接记录的接手人（to）。
     */
    public function test_from_defaults_to_last_handover_to(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        // 预置上一条交接，其接手人为 prev-person，应成为本次的默认交出人。
        OpsShiftHandover::query()->create(['from_assignee' => 'x', 'to_assignee' => 'prev-person', 'open_alert_count' => 0]);

        // 只传 to，from 应自动回填为上一条的 to。
        $this->postJson('/api/ops/alerts/handovers', ['to_assignee' => 'next-person'])->assertOk();

        $latest = OpsShiftHandover::query()->latest('id')->first();
        $this->assertSame('prev-person', $latest->from_assignee);
        $this->assertSame('next-person', $latest->to_assignee);
    }

    /**
     * 验证：既没有当前值班人、又没有显式传 to 时，无法推断接手人，返回 422。
     */
    public function test_422_when_no_current_on_call_and_no_to(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        // 无活跃值班、只传 from，to 无法推断，应校验失败。
        $this->postJson('/api/ops/alerts/handovers', ['from_assignee' => 'alice'])->assertStatus(422);
    }

    /**
     * 验证：交接列表按最新在前排序（后创建的 b 应排在首位）。
     */
    public function test_list_newest_first(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        OpsShiftHandover::query()->create(['to_assignee' => 'a', 'open_alert_count' => 0]);
        OpsShiftHandover::query()->create(['to_assignee' => 'b', 'open_alert_count' => 0]);

        $items = $this->getJson('/api/ops/alerts/handovers')->assertOk()->json('data.items');
        $this->assertSame('b', $items[0]['to_assignee']);
    }

    /**
     * 验证：仅有 view 权限（缺 manage）时创建交接返回 403。
     */
    public function test_create_requires_manage(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->postJson('/api/ops/alerts/handovers', ['to_assignee' => 'bob'])->assertStatus(403);
    }
}
