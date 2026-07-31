<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 36 - 告警批量分组操作测试。
 *
 * 场景：按分组维度（by=source / severity）对一批告警执行批量操作——批量确认、批量指派、
 * 批量静默。用于告警风暴时的成组处置。本文件覆盖：
 *   - 批量确认只影响匹配分组内的 open 告警，不动其他来源与已 resolved 的告警，并落事件与审计日志；
 *   - 批量指派按 severity 分组写入负责人；assigned_to 缺失时 422；
 *   - 批量静默为分组创建一条生效中的 silence；
 *   - 批量操作需 manage 权限；非法的 by 值被拒（422）。
 */
class PhaseThirtySixBatchOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 关闭所有告警通道，避免批量操作触发的通知逃逸。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
    }

    // 构造一条告警，来源/严重度/状态可定制，便于验证分组筛选逻辑。
    private function alert(string $source, string $severity = 'warning', string $status = 'open'): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.$severity.uniqid()),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source}-t",
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    // 验证：按 source=disk 批量确认，只把两条 open 的 disk 告警置为 acknowledged，
    // 不影响 queue 来源与已 resolved 的 disk 告警；affected=2、capped=false，并落事件与审计日志。
    public function test_batch_acknowledge_group_by_source(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $a = $this->alert('disk');                        // 目标：应被确认
        $b = $this->alert('disk');                        // 目标：应被确认
        $other = $this->alert('queue');                   // 不同来源：不应受影响
        $resolved = $this->alert('disk', 'warning', 'resolved'); // 已 resolved：不应被确认

        $this->postJson('/api/ops/alerts/batch/acknowledge', ['by' => 'source', 'group' => 'disk', 'note' => '批量'])
            ->assertOk()
            ->assertJsonPath('data.affected', 2)
            ->assertJsonPath('data.capped', false);

        $this->assertSame('acknowledged', $a->refresh()->status);
        $this->assertSame('acknowledged', $b->refresh()->status);
        $this->assertSame('open', $other->refresh()->status);
        $this->assertSame('resolved', $resolved->refresh()->status);

        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $a->id, 'action' => 'acknowledged']);
        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'batch_acknowledge', 'result' => 'success']);
    }

    // 验证：按 severity=critical 批量指派给 alice，两条 critical 告警的 assigned_to 都变为 alice。
    public function test_batch_assign_group(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        // 两条 critical 告警作为按严重度分组的目标。
        $a = $this->alert('disk', 'critical');
        $b = $this->alert('disk', 'critical');

        $this->postJson('/api/ops/alerts/batch/assign', ['by' => 'severity', 'group' => 'critical', 'assigned_to' => 'alice'])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);

        $this->assertSame('alice', $a->refresh()->assigned_to);
        $this->assertSame('alice', $b->refresh()->assigned_to);
    }

    // 验证：批量指派未提供 assigned_to 时返回 422（指派对象为必填）。
    public function test_batch_assign_requires_assigned_to(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $this->alert('disk');

        $this->postJson('/api/ops/alerts/batch/assign', ['by' => 'source', 'group' => 'disk'])
            ->assertStatus(422);
    }

    // 验证：按 source=disk 批量静默 30 分钟，会创建一条 sources=[disk] 且生效中的 silence 记录，
    // 并返回非空 silence_id。
    public function test_batch_silence_group_creates_silence(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/batch/silence', ['by' => 'source', 'group' => 'disk', 'minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.silence_id', fn ($id) => $id !== null);

        $silence = OpsAlertSilence::query()->firstOrFail();
        $this->assertSame(['disk'], $silence->sources);
        $this->assertTrue($silence->is_active);
    }

    // 验证：批量操作需 manage 权限——仅有 view 时批量确认被拒（403）。
    public function test_batch_requires_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/batch/acknowledge', ['by' => 'source', 'group' => 'disk'])->assertStatus(403);
    }

    // 验证：分组维度 by 只接受白名单值，传入非法值 bogus 时返回 422 并在 by 字段报错。
    public function test_invalid_by_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/batch/acknowledge', ['by' => 'bogus', 'group' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['by']);
    }
}
