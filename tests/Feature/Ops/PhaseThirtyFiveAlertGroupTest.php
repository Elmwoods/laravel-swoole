<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 35：告警分组视图（Alert Grouping）功能测试。
 *
 * 场景：告警中心支持按维度（source / assigned_to）对 open 告警聚合统计，
 * 返回每组总数、按级别拆分与样例，并按 total 倒序。本测试文件覆盖：
 * 按来源分组、按负责人分组（未指派归入 __unassigned__）、
 * 非法维度回退为 source、空数据返回空组、以及接口需 ops.alerts.view 权限。
 */
class PhaseThirtyFiveAlertGroupTest extends TestCase
{
    use RefreshDatabase;

    // 辅助：造一条告警，可指定来源、级别、状态与负责人（默认 open、未指派）。
    private function alert(string $source, string $severity, string $status = 'open', ?string $assigned = null): void
    {
        OpsAlert::query()->create([
            'fingerprint' => sha1($source.$severity.uniqid()),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source}-{$severity}",
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'assigned_to' => $assigned,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * 验证：按来源分组时只统计 open 告警，各组的 total/级别数/样例正确，
     * 且分组按 total 倒序（disk 在 queue 之前）。
     */
    public function test_group_by_source(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->alert('disk', 'critical');
        $this->alert('disk', 'warning');
        $this->alert('queue', 'info');
        $this->alert('disk', 'critical', 'resolved'); // 不计入 open

        $data = $this->getJson('/api/ops/alerts/groups?by=source')->assertOk()->json('data');
        $this->assertSame('source', $data['by']);

        // disk 组：2 条 open（1 critical + 1 warning），resolved 的那条不计入；样例 2 条。
        $disk = collect($data['groups'])->firstWhere('group', 'disk');
        $this->assertSame(2, $disk['total']);
        $this->assertSame(1, $disk['critical']);
        $this->assertSame(1, $disk['warning']);
        $this->assertCount(2, $disk['samples']);
        // 按 total 倒序：disk(2) 在 queue(1) 前。
        $this->assertSame('disk', $data['groups'][0]['group']);
    }

    /**
     * 验证：按负责人分组时，已指派归入对应人名组，未指派归入 __unassigned__ 组。
     */
    public function test_group_by_assigned_to_buckets_unassigned(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->alert('disk', 'critical', 'open', 'alice'); // 指派给 alice
        $this->alert('queue', 'warning', 'open', null);    // 未指派

        $data = $this->getJson('/api/ops/alerts/groups?by=assigned_to')->assertOk()->json('data');
        $groups = collect($data['groups']);

        $this->assertSame(1, $groups->firstWhere('group', 'alice')['total']);
        // 未指派统一落入 __unassigned__ 桶。
        $this->assertSame(1, $groups->firstWhere('group', '__unassigned__')['total']);
    }

    // 验证：传入不支持的分组维度（by=bogus）时回退为默认的 source。
    public function test_invalid_by_falls_back_to_source(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alert('disk', 'critical');

        $this->getJson('/api/ops/alerts/groups?by=bogus')->assertOk()->assertJsonPath('data.by', 'source');
    }

    // 验证：无任何告警时分组接口返回空 groups 数组。
    public function test_empty_returns_empty_groups(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/groups')->assertOk()->assertJsonPath('data.groups', []);
    }

    // 验证：无任何权限时访问分组接口返回 403。
    public function test_requires_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/groups')->assertStatus(403);
    }
}
