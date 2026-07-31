<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertEvent;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 34「SLA 达标率」测试。
 *
 * 覆盖 AlertCenterService::slaSummary() 对告警响应时效的达标统计：按 severity 分级，
 * 分别统计 ack（确认）与 resolve（解决）在各自目标时长内的 within/total/rate，以及 overall 汇总。
 * 目标时长来自默认配置（如 resolve '60,240,1440' 分钟、ack '10,30,120' 分钟）。
 * 手法：用 backdated 的 created_at 造"出生时刻"，再造相应动作事件的时间差来命中/超出目标。
 */
class PhaseThirtyFourSlaComplianceTest extends TestCase
{
    use RefreshDatabase;

    // 造一条告警，并把 created_at 强制设为 $born（saveQuietly 避免触发 updated_at 等模型事件），作为 SLA 计时起点。
    private function alert(string $source, string $severity, Carbon $born, string $status = 'resolved'): OpsAlert
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => $source.'-'.uniqid(),
            'source' => $source,
            'severity' => $severity,
            'title' => 't',
            'message' => 'm',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => $born,
        ]);
        $alert->created_at = $born;
        $alert->saveQuietly();

        return $alert;
    }

    // 造一条告警事件，created_at 指定为 $at——与告警 born 的时间差即为 ack/resolve 耗时。
    private function event(OpsAlert $alert, string $action, Carbon $at): void
    {
        OpsAlertEvent::query()->create(['alert_id' => $alert->id, 'action' => $action, 'actor' => 'x', 'created_at' => $at]);
    }

    // 便捷取近 N 天的 SLA 汇总（默认 30 天窗口）。
    private function sla(int $days = 30): array
    {
        return app(AlertCenterService::class)->slaSummary($days);
    }

    // 验证：resolve 达标按 severity 分别统计。critical 目标 60min，30min 内→达标、2h→超标，得 within=1/total=2/rate=50。
    public function test_resolve_compliance_per_severity(): void
    {
        // critical resolve 目标 60min（默认 '60,240,1440'）→ 3600s。
        $born = now()->subHours(5);

        $within = $this->alert('disk', 'critical', $born->copy());
        $this->event($within, 'resolved', $born->copy()->addSeconds(1800)); // 30min 内 → 达标

        $breach = $this->alert('disk', 'critical', $born->copy());
        $this->event($breach, 'resolved', $born->copy()->addSeconds(7200)); // 2h → 超标

        $compliance = $this->sla()['compliance']['resolve']['critical'];

        $this->assertSame(1, $compliance['within']);
        $this->assertSame(2, $compliance['total']);
        $this->assertSame(50, $compliance['rate']);
    }

    // 验证：ack 达标统计及 overall 汇总。warning ack 目标 30min，两条分别 10min/20min 均达标 → rate=100，overall 也 100。
    public function test_ack_compliance_and_overall(): void
    {
        // warning ack 目标 30min（默认 '10,30,120'）→ 1800s。
        $born = now()->subHours(3);

        $a = $this->alert('queue', 'warning', $born->copy(), 'acknowledged');
        $this->event($a, 'acknowledged', $born->copy()->addSeconds(600)); // 10min → 达标

        $b = $this->alert('queue', 'warning', $born->copy(), 'acknowledged');
        $this->event($b, 'acknowledged', $born->copy()->addSeconds(1200)); // 20min → 达标

        $ack = $this->sla()['compliance']['ack'];

        $this->assertSame(['within' => 2, 'total' => 2, 'rate' => 100], $ack['warning']);
        $this->assertSame(2, $ack['overall']['total']);
        $this->assertSame(100, $ack['overall']['rate']);
    }

    // 验证：某 severity（如 info）没有任何样本时，within/total 为 0 且 rate 为 null（而非 0），overall 无样本也 null。
    public function test_empty_severity_has_null_rate(): void
    {
        $compliance = $this->sla()['compliance']['resolve'];

        $this->assertSame(['within' => 0, 'total' => 0, 'rate' => null], $compliance['info']);
        $this->assertNull($compliance['overall']['rate']);
    }

    // 验证：SLA 接口返回的 JSON 结构包含 compliance.ack（分级 + overall）与 compliance.resolve（overall）等键。
    public function test_endpoint_exposes_compliance(): void
    {
        // 只读接口，view 权限即可。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->getJson('/api/ops/alerts/sla?days=30')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['compliance' => ['ack' => ['critical', 'warning', 'info', 'overall'], 'resolve' => ['overall']]],
            ]);
    }
}
