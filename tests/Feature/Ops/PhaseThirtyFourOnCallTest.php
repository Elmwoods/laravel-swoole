<?php

namespace Tests\Feature\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsOnCallShift;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\OnCallRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyFourOnCallTest extends TestCase
{
    use RefreshDatabase;

    private function shift(string $assignee, int $startOffsetMin, int $endOffsetMin, bool $active = true): OpsOnCallShift
    {
        return OpsOnCallShift::query()->create([
            'assignee' => $assignee,
            'starts_at' => now()->addMinutes($startOffsetMin),
            'ends_at' => now()->addMinutes($endOffsetMin),
            'is_active' => $active,
        ]);
    }

    public function test_current_on_call_resolves_active_covering_shift(): void
    {
        $this->shift('alice', -60, 60);
        $this->assertSame('alice', app(OnCallRotationService::class)->currentOnCall());
    }

    public function test_current_on_call_ignores_out_of_window_and_inactive(): void
    {
        $this->shift('past', -120, -60);       // 已结束
        $this->shift('future', 60, 120);        // 未开始
        $this->shift('disabled', -60, 60, false); // 停用
        $this->assertNull(app(OnCallRotationService::class)->currentOnCall());
    }

    public function test_current_on_call_prefers_most_recently_started(): void
    {
        $this->shift('early', -120, 120);
        $this->shift('late', -10, 120);
        $this->assertSame('late', app(OnCallRotationService::class)->currentOnCall());
    }

    public function test_new_alert_auto_assigned_to_on_call_when_enabled(): void
    {
        config()->set('ops.alerts.on_call.enabled', true);
        $this->shift('on-call-a', -60, 60);

        $service = app(AlertCenterService::class);
        $method = new \ReflectionMethod($service, 'storeAlert');
        $method->setAccessible(true);
        [$alert] = $method->invoke($service, new AlertDTO(
            source: 'disk', severity: 'warning', title: 'Disk', message: 'msg', context: ['target' => '/'],
        ));

        $this->assertSame('on-call-a', $alert->fresh()->assigned_to);
        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $alert->id, 'action' => 'assigned', 'actor' => 'on-call-auto']);
    }

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
        config()->set('ops.alerts.on_call.enabled', true);
        OpsOnCallShift::query()->delete();
        [$alert2] = $method->invoke($service, new AlertDTO(source: 'queue', severity: 'warning', title: 'B', message: 'm'));
        $this->assertNull($alert2->fresh()->assigned_to);
    }

    public function test_crud_and_permissions(): void
    {
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

    public function test_store_validates_end_after_start(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'bob',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->subHour()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_management_requires_manage_permission(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'bob',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
        ])->assertStatus(403);
    }
}
