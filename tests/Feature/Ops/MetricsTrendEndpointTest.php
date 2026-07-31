<?php

namespace Tests\Feature\Ops;

use App\Models\OpsMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 系统指标趋势接口（/api/ops/system/metrics-trend）测试。
 *
 * 该接口按天聚合系统指标采样（OpsMetricSample），返回每日的 CPU 负载与内存占用平均值，
 * 供运维趋势图展示。覆盖场景：接口权限校验（需 ops.system.view）、
 * 以及同一天多条采样时按日求平均的正确性。
 */
class MetricsTrendEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证趋势接口的权限门禁：
     * 未登录返回 401；仅持有无关权限（ops.dashboard.view）时返回 403。
     */
    public function test_metrics_trend_requires_permission(): void
    {
        // 未认证访问，应被拦截为 401
        $this->getJson('/api/ops/system/metrics-trend')->assertStatus(401);

        // 仅持有 ops.dashboard.view（缺少 ops.system.view），应返回 403
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/system/metrics-trend')->assertStatus(403);
    }

    /**
     * 验证接口返回按天聚合的平均值：
     * 当天写入 2 条采样（cpu 2.0/4.0、mem 40/60），当日桶应返回其平均（cpu 3.0、mem 50.0）；
     * 另造 1 条昨天的采样以确认按日期分桶、不与今天混算。
     */
    public function test_metrics_trend_returns_daily_averages(): void
    {
        // 需要 ops.system.view 权限方可访问系统指标
        $this->actingAsAdminWithPermissions(['ops.system.view']);

        // 今天写入两条采样，其 cpu/mem 均值分别为 3.0 与 50.0，用于验证按日求平均
        $this->sampleAt(Carbon::now(), cpu: 2.0, mem: 40.0);
        $this->sampleAt(Carbon::now(), cpu: 4.0, mem: 60.0);
        // 昨天再写一条，作为不同分桶的干扰项，确认不会被算进今天
        $this->sampleAt(Carbon::now()->subDays(1), cpu: 1.0, mem: 20.0);

        // 请求最近 7 天趋势
        $buckets = $this->getJson('/api/ops/system/metrics-trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        // 取出今天对应的桶，断言其为当天两条采样的平均值（用 delta 容差比较浮点）
        $today = collect($buckets)->firstWhere('date', Carbon::now()->toDateString());
        $this->assertEqualsWithDelta(3.0, $today['cpu_load'], 0.001);
        $this->assertEqualsWithDelta(50.0, $today['memory_used_percent'], 0.001);
    }

    /**
     * 测试辅助方法：在指定时间点 $at 写入一条指标采样。
     * 由于 captured_at 可能被模型的时间戳逻辑覆盖，这里额外用 forceFill 强制回填，
     * 确保采样精确落在期望的日期分桶上。
     */
    private function sampleAt(Carbon $at, float $cpu, float $mem): void
    {
        $sample = OpsMetricSample::query()->create([
            'cpu_load' => $cpu,
            'load1' => $cpu,
            'memory_used_percent' => $mem,
            'swap_used_percent' => 0,
            'captured_at' => $at,
        ]);
        // 再次以 forceFill 强制写回 captured_at，防止被自动时间戳改写，保证落在预期日期
        $sample->forceFill(['captured_at' => $at])->save();
    }
}
