<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 39「依赖拓扑」测试。
 *
 * 覆盖 /api/ops/alerts/topology：基于服务依赖关系（如 queue 依赖 mysql）生成节点与边，
 * 标注每个节点是否 firing（有未处理告警）及 open 数量，并在父节点 firing 时把子节点标为 suppressed
 *（关联抑制）。同时验证无告警时全部不 firing，以及接口受 ops.alerts.view 权限保护。
 */
class PhaseThirtyNineTopologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 打开关联/拓扑功能，并声明依赖关系：queue 依赖 mysql（mysql 是 queue 的父节点）。
        config()->set('ops.alerts.correlation.enabled', true);
        config()->set('ops.alerts.correlation.dependencies', ['queue' => ['mysql']]);
    }

    // 便捷造一条指定 source 的 open+critical 告警，使对应拓扑节点进入 firing 状态。
    private function openAlert(string $source): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($source.uniqid()),
            'source' => $source, 'severity' => 'critical', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    // 验证：拓扑的边由依赖关系生成（mysql→queue），mysql 有 open 告警时 firing=true/open=1，queue 无告警则 firing=false。
    public function test_nodes_and_edges_from_deps(): void
    {
        // 只读接口，view 权限即可。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->openAlert('mysql');

        $data = $this->getJson('/api/ops/alerts/topology')->assertOk()->json('data');

        $edge = collect($data['edges'])->firstWhere('to', 'queue');
        $this->assertSame('mysql', $edge['from']);

        $mysql = collect($data['nodes'])->firstWhere('source', 'mysql');
        $this->assertTrue($mysql['firing']);
        $this->assertSame(1, $mysql['open']);

        $queue = collect($data['nodes'])->firstWhere('source', 'queue');
        $this->assertFalse($queue['firing']);
    }

    // 验证：父节点 mysql 与子节点 queue 都在 firing 时，子节点 queue 被标记 suppressed=true（根因在父节点，抑制子告警噪音）。
    public function test_child_suppressed_when_parent_firing(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        // 父子节点同时告警，触发关联抑制。
        $this->openAlert('mysql');
        $this->openAlert('queue');

        $data = $this->getJson('/api/ops/alerts/topology')->assertOk()->json('data');
        $queue = collect($data['nodes'])->firstWhere('source', 'queue');
        $this->assertTrue($queue['firing']);
        $this->assertTrue($queue['suppressed']); // 父 mysql firing
    }

    // 验证：没有任何 open 告警时，所有拓扑节点的 firing 均为 false。
    public function test_no_open_all_not_firing(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $data = $this->getJson('/api/ops/alerts/topology')->assertOk()->json('data');
        foreach ($data['nodes'] as $node) {
            $this->assertFalse($node['firing']);
        }
    }

    // 验证：无权限访问拓扑接口返回 403。
    public function test_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/topology')->assertStatus(403);
    }
}
