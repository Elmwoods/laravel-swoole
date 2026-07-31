<?php

namespace Tests\Feature\Ops;

use App\Models\OpsRedisMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * /api/ops/redis-metrics/trend 趋势接口测试。
 *
 * 场景：该接口把 Redis 指标采样按天分桶，返回每日 ops、命中率等平均值。
 * 覆盖权限门禁（未登录 401、权限不足 403）与按日聚合求平均的正确性。
 */
class RedisMetricsTrendEndpointTest extends TestCase
{
    use RefreshDatabase;

    // 验证权限门禁：未登录访问返回 401；仅有无关权限（ops.dashboard.view）返回 403。
    public function test_trend_requires_permission(): void
    {
        // 未认证 → 401。
        $this->getJson('/api/ops/redis-metrics/trend')->assertStatus(401);

        // 已登录但持有的是 dashboard.view 而非本接口所需的 system.view → 403。
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/ops/redis-metrics/trend')->assertStatus(403);
    }

    // 验证按日聚合求平均：今天两条样本(200/400 ops、80/90 命中率)应聚合成 ops=300、hit_rate=85 的当日桶。
    public function test_trend_returns_daily_averages(): void
    {
        // 本接口需要 ops.system.view 权限。
        $this->actingAsAdminWithPermissions(['ops.system.view']);

        // 今天两条样本用于验证同一天内取平均；昨天一条用于验证跨天分桶不混淆。
        $this->sampleAt(Carbon::now(), ops: 200, hit: 80.0);
        $this->sampleAt(Carbon::now(), ops: 400, hit: 90.0);
        $this->sampleAt(Carbon::now()->subDays(1), ops: 100, hit: 70.0);

        $buckets = $this->getJson('/api/ops/redis-metrics/trend?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->json('data.buckets');

        // 定位"今天"这个桶，断言其为两条样本的平均值：ops=(200+400)/2=300、hit=(80+90)/2=85。
        $today = collect($buckets)->firstWhere('date', Carbon::now()->toDateString());
        $this->assertEqualsWithDelta(300.0, $today['ops'], 0.001);
        $this->assertEqualsWithDelta(85.0, $today['hit_rate'], 0.001);
    }

    // 造一条指定时间点的 Redis 样本；create 后用 forceFill 回写 captured_at 锁定分桶所依据的时间。
    private function sampleAt(Carbon $at, int $ops, float $hit): void
    {
        $sample = OpsRedisMetricSample::query()->create([
            'ops' => $ops,
            'clients' => 10,
            'memory_mb' => 20,
            'hit_rate' => $hit,
            'captured_at' => $at,
        ]);
        // forceFill 直接回写 captured_at，确保样本落在预期的日期桶内。
        $sample->forceFill(['captured_at' => $at])->save();
    }
}
