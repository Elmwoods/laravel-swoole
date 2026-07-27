<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use App\Services\Ops\AlertSilenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyTwoRecurringSilenceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): AlertSilenceService
    {
        return app(AlertSilenceService::class);
    }

    private function alert(string $source = 'disk', string $severity = 'critical'): OpsAlert
    {
        return new OpsAlert(['source' => $source, 'severity' => $severity, 'title' => 't', 'message' => 'm', 'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now()]);
    }

    private function silence(array $overrides = []): OpsAlertSilence
    {
        return OpsAlertSilence::query()->create(array_merge([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'recurrence' => 'once',
            'sources' => [],
            'severities' => [],
            'is_active' => true,
        ], $overrides));
    }

    public function test_daily_window_covering_now_is_silenced(): void
    {
        $this->silence([
            'recurrence' => 'daily',
            'start_time' => now()->subMinutes(30)->format('H:i'),
            'end_time' => now()->addMinutes(30)->format('H:i'),
        ]);

        $this->assertTrue($this->svc()->isSilenced($this->alert()));
    }

    public function test_daily_window_not_covering_now_is_not_silenced(): void
    {
        $this->silence([
            'recurrence' => 'daily',
            'start_time' => now()->addHours(2)->format('H:i'),
            'end_time' => now()->addHours(3)->format('H:i'),
        ]);

        $this->assertFalse($this->svc()->isSilenced($this->alert()));
    }

    public function test_weekly_matches_only_on_listed_days(): void
    {
        $covering = ['start_time' => now()->subMinutes(30)->format('H:i'), 'end_time' => now()->addMinutes(30)->format('H:i')];

        // 今天在列表 → 命中。
        $s = $this->silence(array_merge(['recurrence' => 'weekly', 'days_of_week' => [(int) now()->dayOfWeek]], $covering));
        $this->assertTrue($this->svc()->isSilenced($this->alert()));

        // 换成不含今天 → 不命中。
        $s->update(['days_of_week' => [((int) now()->dayOfWeek + 3) % 7]]);
        $this->assertFalse($this->svc()->isSilenced($this->alert()));
    }

    public function test_once_still_uses_absolute_window(): void
    {
        // 绝对窗口覆盖 now → 命中（回归 phase-29）。
        $this->silence(['recurrence' => 'once']);
        $this->assertTrue($this->svc()->isSilenced($this->alert()));
    }

    public function test_outside_effective_range_not_silenced_even_if_time_matches(): void
    {
        $this->silence([
            'starts_at' => now()->addDay(), // 生效范围在未来
            'ends_at' => now()->addDays(2),
            'recurrence' => 'daily',
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);

        $this->assertFalse($this->svc()->isSilenced($this->alert()));
    }

    public function test_active_silences_respects_recurrence(): void
    {
        $this->silence([
            'recurrence' => 'daily',
            'start_time' => now()->addHours(2)->format('H:i'),
            'end_time' => now()->addHours(3)->format('H:i'),
        ]);

        $this->assertCount(0, $this->svc()->activeSilences()); // 生效范围内但当前不在时段
    }

    public function test_create_daily_via_api(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $id = $this->postJson('/api/ops/alerts/silences', [
            'label' => '每晚跑批',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addWeek()->toDateTimeString(),
            'recurrence' => 'daily',
            'start_time' => '02:00',
            'end_time' => '04:00',
            'sources' => ['disk'],
        ])->assertOk()->json('data.silence.id');

        $this->assertDatabaseHas('ops_alert_silences', ['id' => $id, 'recurrence' => 'daily', 'start_time' => '02:00', 'end_time' => '04:00']);
    }

    public function test_daily_requires_times_and_weekly_requires_days(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $base = ['starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addWeek()->toDateTimeString()];

        $this->postJson('/api/ops/alerts/silences', array_merge($base, ['recurrence' => 'daily']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time']);

        $this->postJson('/api/ops/alerts/silences', array_merge($base, ['recurrence' => 'weekly', 'start_time' => '02:00', 'end_time' => '04:00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['days_of_week']);
    }
}
