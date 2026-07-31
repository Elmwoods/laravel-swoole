<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 39「值班人工作量统计」测试。
 *
 * 覆盖 AlertCenterService::workloadSummary()：按处理人（actor）聚合 ack/resolve 次数与平均耗时、
 * 被指派数量；排除系统自动 actor（如 ops-auto-resolver），并按时间窗口过滤掉过旧事件。
 * 同时验证 /api/ops/alerts/workload 接口的 days 参数回显与权限保护。
 */
class PhaseThirtyNineWorkloadTest extends TestCase
{
    use RefreshDatabase;

    // 造一条告警并把 created_at 强制回填为 $born（saveQuietly 不触发模型事件），可选指定 assigned_to 计入被指派数。
    private function alert(Carbon $born, ?string $assigned = null): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1(uniqid()),
            'source' => 'disk', 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'resolved', 'hit_count' => 1, 'last_seen_at' => $born, 'assigned_to' => $assigned,
        ]);
        $alert->created_at = $born;
        $alert->saveQuietly();

        return $alert;
    }

    // 造一条告警事件，指定 actor 与发生时刻 $at——与告警 born 的时间差即为该动作耗时。
    private function event(OpsAlert $alert, string $action, string $actor, Carbon $at): void
    {
        OpsAlertEvent::query()->create([
            'alert_id' => $alert->id, 'action' => $action, 'actor' => $actor, 'created_at' => $at,
        ]);
    }

    // 验证：按 actor 聚合工作量——alice 有 2 次 ack（平均 180s）、1 次 resolve（600s）、被指派 2 条；系统 actor ops-auto-resolver 不计入。
    public function test_workload_aggregates_by_actor(): void
    {
        // 统一以 3 小时前为告警出生时刻，落在默认统计窗口内。
        $born = now()->subHours(3);
        $a1 = $this->alert($born, 'alice');
        $this->event($a1, 'acknowledged', 'alice', $born->copy()->addSeconds(120));
        $this->event($a1, 'resolved', 'alice', $born->copy()->addSeconds(600));

        $a2 = $this->alert($born, 'alice');
        $this->event($a2, 'acknowledged', 'alice', $born->copy()->addSeconds(240));

        // 系统 actor 不计入。
        $a3 = $this->alert($born);
        $this->event($a3, 'auto_resolved', 'ops-auto-resolver', $born->copy()->addSeconds(300));

        $summary = app(AlertCenterService::class)->workloadSummary(7);
        $alice = collect($summary['people'])->firstWhere('person', 'alice');

        $this->assertSame(2, $alice['acknowledged_count']);
        $this->assertSame(180, $alice['avg_ack_seconds']); // (120+240)/2
        $this->assertSame(1, $alice['resolved_count']);
        $this->assertSame(600, $alice['avg_resolve_seconds']);
        $this->assertSame(2, $alice['assigned_count']);

        // 无 ops-auto-resolver 这一"人"。
        $this->assertNull(collect($summary['people'])->firstWhere('person', 'ops-auto-resolver'));
    }

    // 验证：时间窗口过滤——40 天前的事件落在 7 天窗口之外，因此 people 为空。
    public function test_window_excludes_old_events(): void
    {
        // 出生时刻放在 40 天前，超出下面 workloadSummary(7) 的 7 天窗口。
        $born = now()->subDays(40);
        $a = $this->alert($born, 'bob');
        $this->event($a, 'resolved', 'bob', $born->copy()->addSeconds(60));

        $summary = app(AlertCenterService::class)->workloadSummary(7);
        $this->assertSame([], $summary['people']);
    }

    // 验证：工作量接口正常返回，data.days 与 data.window_days 都回显请求的 30。
    public function test_endpoint(): void
    {
        // 只读接口，view 权限即可。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->getJson('/api/ops/alerts/workload?days=30')
            ->assertOk()
            ->assertJsonPath('data.days', 30)
            ->assertJsonPath('data.window_days', 30);
    }

    // 验证：无权限访问工作量接口返回 403。
    public function test_endpoint_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/workload')->assertStatus(403);
    }
}
