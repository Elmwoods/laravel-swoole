<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertEvaluation;
use App\Models\OpsInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 13：巡检与告警趋势（Trend）功能测试。
 *
 * 场景：仪表盘按天展示最近 N 天的巡检结果分布与告警评估统计。
 * 本测试文件覆盖：两个趋势接口的鉴权（未登录 401、缺专属权限 403），
 * 巡检趋势按天分桶统计 pass/warn/fail/total，
 * 以及告警趋势按天汇总 evaluations/detected/auto_resolved。
 */
class PhaseThirteenTrendTest extends TestCase
{
    use RefreshDatabase;

    // 验证：巡检趋势接口未登录返回 401；仅有 dashboard 权限（缺 ops.inspections.view）返回 403。
    public function test_inspection_trend_requires_permission(): void
    {
        $this->getJson('/api/ops/inspections/trend')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/inspections/trend')->assertStatus(403);
    }

    // 验证：告警趋势接口未登录返回 401；仅有 dashboard 权限（缺 ops.alerts.view）返回 403。
    public function test_alert_trend_requires_permission(): void
    {
        $this->getJson('/api/ops/alerts/trend')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/alerts/trend')->assertStatus(403);
    }

    /**
     * 验证：巡检趋势按自然日分桶——今天两条（1 pass + 1 fail），两天前一条 warn，
     * 返回固定 7 个桶，各桶按状态与总数正确计数。
     */
    public function test_inspection_trend_buckets_by_day(): void
    {
        $this->actingAsAdminWithPermissions(['ops.inspections.view']);

        // 今天造两条巡检：一条通过、一条失败。
        $this->inspectionOn(now(), 'pass');
        $this->inspectionOn(now(), 'fail');
        // 两天前造一条 warn，用于验证跨天分桶。
        $this->inspectionOn(now()->subDays(2), 'warn');

        $response = $this->getJson('/api/ops/inspections/trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        // days=7 应固定返回 7 个日期桶。
        $this->assertCount(7, $response);

        // 今天桶：1 通过、1 失败、0 警告、共 2 条。
        $today = collect($response)->firstWhere('date', now()->toDateString());
        $this->assertSame(1, $today['pass']);
        $this->assertSame(1, $today['fail']);
        $this->assertSame(0, $today['warn']);
        $this->assertSame(2, $today['total']);

        // 两天前桶：1 警告、共 1 条。
        $twoDaysAgo = collect($response)->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertSame(1, $twoDaysAgo['warn']);
        $this->assertSame(1, $twoDaysAgo['total']);
    }

    /**
     * 验证：告警趋势按天汇总——同一天多次评估的 detected/auto_resolved 相加，
     * evaluations 计次；不同天各自独立统计。
     */
    public function test_alert_trend_sums_by_day(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        // 今天两次评估：检出 3+2=5，自动解决 1+0=1，共 2 次评估。
        $this->evaluationOn(now(), detected: 3, autoResolved: 1);
        $this->evaluationOn(now(), detected: 2, autoResolved: 0);
        // 昨天一次评估：检出 5，自动解决 2。
        $this->evaluationOn(now()->subDays(1), detected: 5, autoResolved: 2);

        $response = $this->getJson('/api/ops/alerts/trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        // days=7 应固定返回 7 个日期桶。
        $this->assertCount(7, $response);

        // 今天桶：2 次评估、检出合计 5、自动解决合计 1。
        $today = collect($response)->firstWhere('date', now()->toDateString());
        $this->assertSame(2, $today['evaluations']);
        $this->assertSame(5, $today['detected']);
        $this->assertSame(1, $today['auto_resolved']);

        // 昨天桶：检出 5、自动解决 2。
        $yesterday = collect($response)->firstWhere('date', now()->subDays(1)->toDateString());
        $this->assertSame(5, $yesterday['detected']);
        $this->assertSame(2, $yesterday['auto_resolved']);
    }

    // 辅助：在指定时间点造一条巡检记录，并用 forceFill 回写 created_at 以落到目标日期桶。
    private function inspectionOn(Carbon $at, string $status): void
    {
        $record = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => $status,
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0],
            'checks' => [],
            'duration_ms' => 10,
            'started_at' => $at,
            'finished_at' => $at,
        ]);
        // create() 会把 created_at 设为当前时间，这里强制回写到目标时间以便按天分桶。
        $record->forceFill(['created_at' => $at])->save();
    }

    // 辅助：在指定时间点造一条告警评估记录，同样回写 created_at 落到目标日期桶。
    private function evaluationOn(Carbon $at, int $detected, int $autoResolved): void
    {
        $record = OpsAlertEvaluation::query()->create([
            'trigger' => 'schedule',
            'status' => 'success',
            'detected_count' => $detected,
            'auto_resolved_count' => $autoResolved,
            'started_at' => $at,
            'finished_at' => $at,
            'duration_ms' => 20,
            'message' => null,
        ]);
        // 强制回写 created_at，使记录归入目标日期桶。
        $record->forceFill(['created_at' => $at])->save();
    }
}
