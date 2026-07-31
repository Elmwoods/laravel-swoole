<?php

namespace Tests\Feature\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsOnCallShift;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\OnCallRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 34「值班表 + 自动分派」测试。
 *
 * 覆盖 OnCallRotationService 的当前值班人选取（生效窗口、启用状态、最近开始优先），
 * 新告警在 on_call 开启时自动分派给值班人并写审计事件，以及值班班次 CRUD 接口的权限与校验。
 * 本文件针对 once 型（简单区间）班次；循环班次由 PhaseThirtyFiveOnCallRecurrenceTest 覆盖。
 */
class PhaseThirtyFourOnCallTest extends TestCase
{
    use RefreshDatabase;

    // 便捷造班次：以"当前时间 + 分钟偏移"设定 starts_at/ends_at，负偏移表示过去、正偏移表示将来。
    private function shift(string $assignee, int $startOffsetMin, int $endOffsetMin, bool $active = true): OpsOnCallShift
    {
        return OpsOnCallShift::query()->create([
            'assignee' => $assignee,
            'starts_at' => now()->addMinutes($startOffsetMin),
            'ends_at' => now()->addMinutes($endOffsetMin),
            'is_active' => $active,
        ]);
    }

    // 验证：存在一个覆盖当前时间（前后各 60 分钟）的启用班次时，currentOnCall() 返回该值班人。
    public function test_current_on_call_resolves_active_covering_shift(): void
    {
        $this->shift('alice', -60, 60);
        $this->assertSame('alice', app(OnCallRotationService::class)->currentOnCall());
    }

    // 验证：已结束、未开始、被停用三类班次都不应被选中，currentOnCall() 返回 null。
    public function test_current_on_call_ignores_out_of_window_and_inactive(): void
    {
        $this->shift('past', -120, -60);       // 已结束
        $this->shift('future', 60, 120);        // 未开始
        $this->shift('disabled', -60, 60, false); // 停用
        $this->assertNull(app(OnCallRotationService::class)->currentOnCall());
    }

    // 验证：多个班次同时覆盖当前时间时，选取"最近才开始"的那个（late 的 starts_at 晚于 early）。
    public function test_current_on_call_prefers_most_recently_started(): void
    {
        $this->shift('early', -120, 120);
        $this->shift('late', -10, 120);
        $this->assertSame('late', app(OnCallRotationService::class)->currentOnCall());
    }

    // 验证：开启 on_call 后，新告警入库时自动分派给当前值班人，并写入 action=assigned / actor=on-call-auto 的事件。
    public function test_new_alert_auto_assigned_to_on_call_when_enabled(): void
    {
        // 打开 on-call 自动分派开关。
        config()->set('ops.alerts.on_call.enabled', true);
        $this->shift('on-call-a', -60, 60);

        $service = app(AlertCenterService::class);
        // storeAlert 受保护，反射调用以走完整入库+自动分派流程。
        $method = new \ReflectionMethod($service, 'storeAlert');
        $method->setAccessible(true);
        [$alert] = $method->invoke($service, new AlertDTO(
            source: 'disk', severity: 'warning', title: 'Disk', message: 'msg', context: ['target' => '/'],
        ));

        $this->assertSame('on-call-a', $alert->fresh()->assigned_to);
        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $alert->id, 'action' => 'assigned', 'actor' => 'on-call-auto']);
    }

    // 验证两种"不自动分派"场景：开关关闭时不分派；开关开启但无活跃班次时也不分派。
    public function test_no_auto_assign_when_disabled_or_no_on_call(): void
    {
        // disabled
        config()->set('ops.alerts.on_call.enabled', false);
        $this->shift('on-call-a', -60, 60);
        $service = app(AlertCenterService::class);
        $method = new \ReflectionMethod($service, 'storeAlert');
        $method->setAccessible(true);
        [$alert] = $method->invoke($service, new AlertDTO(source: 'disk', severity: 'warning', title: 'A', message: 'm'));
        $this->assertNull($alert->fresh()->assigned_to);

        // enabled but no active shift
        // 开关打开，但先清空所有班次，制造"无人值班"场景 → 仍不分派。
        config()->set('ops.alerts.on_call.enabled', true);
        OpsOnCallShift::query()->delete();
        [$alert2] = $method->invoke($service, new AlertDTO(source: 'queue', severity: 'warning', title: 'B', message: 'm'));
        $this->assertNull($alert2->fresh()->assigned_to);
    }

    // 验证：值班班次的完整 CRUD 流程（创建→列表→改停用→删除）都成功，且创建动作写入 admin_audit_logs 审计。
    public function test_crud_and_permissions(): void
    {
        // 需 view+manage：view 读列表，manage 执行增改删。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'bob',
            'label' => '夜班',
            'starts_at' => now()->subMinutes(10)->toDateTimeString(),
            'ends_at' => now()->addHours(8)->toDateTimeString(),
        ])->assertOk();

        $body = $this->getJson('/api/ops/alerts/on-call')->assertOk()->json('data');
        $this->assertSame('bob', $body['current']);
        $this->assertCount(1, $body['items']);
        $this->assertTrue($body['items'][0]['current']);

        $id = OpsOnCallShift::query()->firstOrFail()->id;
        $this->patchJson("/api/ops/alerts/on-call/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.updated', true);
        $this->deleteJson("/api/ops/alerts/on-call/{$id}")->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'ops.alerts', 'action' => 'on_call_create', 'result' => 'success']);
    }

    // 验证：创建时 ends_at 早于 starts_at（结束在开始之前）应触发 422 校验错误。
    public function test_store_validates_end_after_start(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'bob',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->subHour()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    // 验证：只有 view 权限、缺少 manage 权限时，创建班次返回 403（写操作被拦）。
    public function test_management_requires_manage_permission(): void
    {
        // 故意只给 view，不给 manage，以验证写接口的权限门。
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'bob',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
        ])->assertStatus(403);
    }
}
