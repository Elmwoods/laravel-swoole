<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use App\Services\Ops\AlertSilenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ops Center 第 32 阶段：告警静默的周期性（recurrence）能力测试。
 *
 * 覆盖场景：静默规则在原有绝对时间窗（once）基础上，新增 daily/weekly 周期模式——
 * daily 用每天的 start_time~end_time 时段判断当前是否命中，weekly 额外要求当天在 days_of_week 列表内；
 * 周期时段的判断仍受 starts_at~ends_at 生效范围约束；并验证 API 创建 daily 静默、
 * 以及 daily 缺时段/weekly 缺星期时的表单校验。
 */
class PhaseThirtyTwoRecurringSilenceTest extends TestCase
{
    use RefreshDatabase;

    // 取静默判断服务实例。
    private function svc(): AlertSilenceService
    {
        return app(AlertSilenceService::class);
    }

    // 构造一条未落库的告警样本（默认 disk/critical），仅用于喂给 isSilenced 判断。
    private function alert(string $source = 'disk', string $severity = 'critical'): OpsAlert
    {
        return new OpsAlert(['source' => $source, 'severity' => $severity, 'title' => 't', 'message' => 'm', 'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now()]);
    }

    // 创建一条静默规则：默认生效范围为“昨天~明天”（覆盖 now）、once 模式、来源/严重级不限、启用；
    // 各用例通过 overrides 覆盖 recurrence/时段/星期等字段。
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

    /**
     * 验证 daily 周期：每日时段覆盖当前时刻时命中静默。
     * 时段取 now±30 分钟，保证当前时刻落在窗内 → isSilenced 为真。
     */
    public function test_daily_window_covering_now_is_silenced(): void
    {
        $this->silence([
            'recurrence' => 'daily',
            // 时段设为“当前前 30 分钟 ~ 后 30 分钟”，确保 now 落在窗内。
            'start_time' => now()->subMinutes(30)->format('H:i'),
            'end_time' => now()->addMinutes(30)->format('H:i'),
        ]);

        $this->assertTrue($this->svc()->isSilenced($this->alert()));
    }

    /**
     * 验证 daily 周期：每日时段不覆盖当前时刻时不命中。
     * 时段取 now+2h~+3h（当前时刻在窗外）→ isSilenced 为假。
     */
    public function test_daily_window_not_covering_now_is_not_silenced(): void
    {
        $this->silence([
            'recurrence' => 'daily',
            // 时段设在未来 2~3 小时，当前时刻不在其中。
            'start_time' => now()->addHours(2)->format('H:i'),
            'end_time' => now()->addHours(3)->format('H:i'),
        ]);

        $this->assertFalse($this->svc()->isSilenced($this->alert()));
    }

    /**
     * 验证 weekly 周期：在时段覆盖当前时刻的前提下，仅当“今天”在 days_of_week 列表内才命中。
     * 先用包含今天的星期列表验证命中，再改为不含今天（今天+3 天）验证不命中。
     */
    public function test_weekly_matches_only_on_listed_days(): void
    {
        // 公共时段：now±30 分钟，确保当前时刻始终落在时段内，从而只由星期条件决定命中与否。
        $covering = ['start_time' => now()->subMinutes(30)->format('H:i'), 'end_time' => now()->addMinutes(30)->format('H:i')];

        // 今天在列表 → 命中。
        $s = $this->silence(array_merge(['recurrence' => 'weekly', 'days_of_week' => [(int) now()->dayOfWeek]], $covering));
        $this->assertTrue($this->svc()->isSilenced($this->alert()));

        // 换成不含今天 → 不命中。
        $s->update(['days_of_week' => [((int) now()->dayOfWeek + 3) % 7]]);
        $this->assertFalse($this->svc()->isSilenced($this->alert()));
    }

    /**
     * 回归：验证 once 模式仍沿用绝对时间窗（starts_at~ends_at），不受周期时段影响。
     */
    public function test_once_still_uses_absolute_window(): void
    {
        // 绝对窗口覆盖 now → 命中（回归 phase-29）。
        $this->silence(['recurrence' => 'once']);
        $this->assertTrue($this->svc()->isSilenced($this->alert()));
    }

    /**
     * 验证生效范围优先级：即使每日时段（00:00~23:59）覆盖当前时刻，
     * 只要 starts_at~ends_at 生效范围不含 now（设在未来），也不命中静默。
     */
    public function test_outside_effective_range_not_silenced_even_if_time_matches(): void
    {
        $this->silence([
            'starts_at' => now()->addDay(), // 生效范围在未来
            'ends_at' => now()->addDays(2),
            'recurrence' => 'daily',
            // 时段设为全天，确保排除命中的原因只能是“生效范围未到”。
            'start_time' => '00:00',
            'end_time' => '23:59',
        ]);

        $this->assertFalse($this->svc()->isSilenced($this->alert()));
    }

    /**
     * 验证 activeSilences() 也遵守周期时段：规则在生效范围内但当前不在每日时段时，不计入当前生效静默。
     */
    public function test_active_silences_respects_recurrence(): void
    {
        $this->silence([
            'recurrence' => 'daily',
            // 时段在未来 2~3 小时，当前不在时段内。
            'start_time' => now()->addHours(2)->format('H:i'),
            'end_time' => now()->addHours(3)->format('H:i'),
        ]);

        $this->assertCount(0, $this->svc()->activeSilences()); // 生效范围内但当前不在时段
    }

    /**
     * 验证通过 API 创建 daily 静默：提交 recurrence=daily 及时段后，记录正确落库。
     */
    public function test_create_daily_via_api(): void
    {
        // 创建静默需 view + manage 权限。
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

    /**
     * 验证周期模式的条件必填校验：daily 缺 start_time/end_time 报 422，
     * weekly 即使给了时段但缺 days_of_week 也报 422。
     */
    public function test_daily_requires_times_and_weekly_requires_days(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        // 公共基底：合法的绝对生效范围，聚焦于周期字段的校验。
        $base = ['starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addWeek()->toDateTimeString()];

        // daily 但未给时段 → start_time/end_time 必填错误。
        $this->postJson('/api/ops/alerts/silences', array_merge($base, ['recurrence' => 'daily']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time']);

        // weekly 给了时段但未给星期 → days_of_week 必填错误。
        $this->postJson('/api/ops/alerts/silences', array_merge($base, ['recurrence' => 'weekly', 'start_time' => '02:00', 'end_time' => '04:00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['days_of_week']);
    }
}
