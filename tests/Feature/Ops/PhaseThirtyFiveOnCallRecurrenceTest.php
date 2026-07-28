<?php

namespace Tests\Feature\Ops;

use App\Models\OpsOnCallShift;
use App\Services\Ops\OnCallRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

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

    private function svc(): OnCallRotationService
    {
        return app(OnCallRotationService::class);
    }

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

    public function test_cross_midnight_daily_window(): void
    {
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

    public function test_create_validates_recurrence_fields(): void
    {
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

    public function test_create_persists_weekly_shift(): void
    {
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
