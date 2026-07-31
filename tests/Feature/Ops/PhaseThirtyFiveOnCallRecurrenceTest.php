<?php

namespace Tests\Feature\Ops;

use App\Models\OpsOnCallShift;
use App\Services\Ops\OnCallRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 35 值班排班「循环规则」测试。
 *
 * 覆盖 OpsOnCallShift 的 recurrence 能力：once（一次性）、daily（每日时段）、
 * weekly（按星期几 + 时段），以及跨午夜时段、生效范围（starts_at/ends_at）门控，
 * 并验证创建接口对 recurrence 相关字段的校验与持久化。
 * 整体场景：把"当前时间"固定到某个已知的周三 12:00，据此断言 currentOnCall() 选出的值班人。
 */
class PhaseThirtyFiveOnCallRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 固定"现在"到周三 12:00，便于时段/星期断言（Carbon dayOfWeek: 周三=3）。
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00')); // 2026-07-29 是周三
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // 便捷取值班轮转服务实例，供各用例调用 currentOnCall()。
    private function svc(): OnCallRotationService
    {
        return app(OnCallRotationService::class);
    }

    // 验证：daily 循环班次，当前时间（周三 12:00）落在 09:00-18:00 时段内 → 命中该值班人。
    public function test_daily_shift_matches_within_time_window(): void
    {
        OpsOnCallShift::query()->create([
            'assignee' => 'daily-a',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(10),
            'recurrence' => 'daily',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        $this->assertSame('daily-a', $this->svc()->currentOnCall());
    }

    // 验证：daily 班次时段为 20:00-23:00，当前 12:00 不在窗口内 → 不选中，返回 null。
    public function test_daily_shift_out_of_time_window_not_selected(): void
    {
        OpsOnCallShift::query()->create([
            'assignee' => 'night',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(10),
            'recurrence' => 'daily',
            'start_time' => '20:00',
            'end_time' => '23:00',
            'is_active' => true,
        ]);

        $this->assertNull($this->svc()->currentOnCall());
    }

    // 验证：weekly 循环只在配置的星期几生效。当前是周三，仅 days_of_week=[3] 的班次命中，
    // days_of_week=[1]（周一）的班次被排除。
    public function test_weekly_shift_matches_only_on_configured_day(): void
    {
        OpsOnCallShift::query()->create([
            'assignee' => 'wed-person',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(30),
            'recurrence' => 'weekly',
            'days_of_week' => [3], // 周三
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);
        OpsOnCallShift::query()->create([
            'assignee' => 'mon-person',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(30),
            'recurrence' => 'weekly',
            'days_of_week' => [1], // 周一
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        $this->assertSame('wed-person', $this->svc()->currentOnCall());
    }

    // 验证：跨午夜时段（22:00-06:00）的 daily 班次。把当前时间改到 23:30，
    // 虽然 end_time 数值上小于 start_time，仍应判定为落在窗口内 → 命中。
    public function test_cross_midnight_daily_window(): void
    {
        // 覆盖 setUp 的固定时间为当天 23:30，落入跨午夜时段的前半段。
        Carbon::setTestNow(Carbon::parse('2026-07-29 23:30:00'));
        OpsOnCallShift::query()->create([
            'assignee' => 'overnight',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(10),
            'recurrence' => 'daily',
            'start_time' => '22:00',
            'end_time' => '06:00', // 跨午夜
            'is_active' => true,
        ]);

        $this->assertSame('overnight', $this->svc()->currentOnCall());
    }

    // 验证：生效范围（starts_at/ends_at）是前置门控。即使每日时段命中，
    // 只要 starts_at 还在未来（+2 天），该班次也不生效 → 返回 null。
    public function test_effective_range_gate_applies_even_if_time_matches(): void
    {
        OpsOnCallShift::query()->create([
            'assignee' => 'future',
            'starts_at' => now()->addDays(2), // 生效范围未开始
            'ends_at' => now()->addDays(10),
            'recurrence' => 'daily',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        $this->assertNull($this->svc()->currentOnCall());
    }

    // 回归验证：once（一次性）班次不涉及时段/星期逻辑，只要当前落在 starts_at~ends_at 之间即命中。
    public function test_once_shift_regression(): void
    {
        OpsOnCallShift::query()->create([
            'assignee' => 'once-a',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'recurrence' => 'once',
            'is_active' => true,
        ]);

        $this->assertSame('once-a', $this->svc()->currentOnCall());
    }

    // 验证：创建接口对 recurrence 关联字段的条件校验——daily 必须带时段、weekly 必须带 days_of_week，缺失即 422。
    public function test_create_validates_recurrence_fields(): void
    {
        // 需要 view+manage 权限：管理值班班次属于写操作，view 用于读列表、manage 用于增改删。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        // daily 缺 start_time → 422
        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'x',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
            'recurrence' => 'daily',
        ])->assertStatus(422)->assertJsonValidationErrors(['start_time', 'end_time']);

        // weekly 缺 days_of_week → 422
        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'x',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
            'recurrence' => 'weekly',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['days_of_week']);
    }

    // 验证：创建 weekly 班次的持久化——days_of_week 会去重并排序，列表接口能读回，且当前时间命中时 current=true。
    public function test_create_persists_weekly_shift(): void
    {
        // 写操作需 view+manage 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $this->postJson('/api/ops/alerts/on-call', [
            'assignee' => 'weekly-b',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'ends_at' => now()->addMonth()->toDateTimeString(),
            'recurrence' => 'weekly',
            'days_of_week' => [3, 3, 1], // 去重排序 → [1,3]
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertOk();

        $shift = OpsOnCallShift::query()->firstOrFail();
        $this->assertSame('weekly', $shift->recurrence);
        $this->assertSame([1, 3], $shift->days_of_week);

        $items = $this->getJson('/api/ops/alerts/on-call')->json('data.items');
        $this->assertSame('weekly', $items[0]['recurrence']);
        $this->assertTrue($items[0]['current']); // 周三 12:00 命中
    }
}
