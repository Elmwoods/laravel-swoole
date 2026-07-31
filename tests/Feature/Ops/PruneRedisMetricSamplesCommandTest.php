<?php

namespace Tests\Feature\Ops;

use App\Models\OpsRedisMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ops:redis-metrics:prune 命令测试：按保留天数清理 Redis 指标采样表（ops_redis_metric_samples）。
 *
 * 与系统指标 prune 命令行为一致，覆盖 dry-run 只报告不删、
 * 正式运行只删超保留期旧样本、保留天数过小时命令失败且不删数据三种场景。
 */
class PruneRedisMetricSamplesCommandTest extends TestCase
{
    use RefreshDatabase;

    // 验证 dry-run：预演清理成功返回，但 45 天前的旧样本仍保留。
    public function test_dry_run_reports_without_deleting(): void
    {
        // 45 天前样本，本应被 30 天保留期删除，但 dry-run 下应保留。
        $old = $this->sampleDaysAgo(45);

        $this->artisan('ops:redis-metrics:prune', ['--days' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_redis_metric_samples', ['id' => $old->id]);
    }

    // 验证正式清理只删超过保留期的样本：45 天前的被删，10 天前的保留。
    public function test_prune_deletes_only_samples_older_than_retention(): void
    {
        $old = $this->sampleDaysAgo(45);   // 超过 30 天保留期 → 应删
        $fresh = $this->sampleDaysAgo(10); // 在保留期内 → 应留

        $this->artisan('ops:redis-metrics:prune', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('ops_redis_metric_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_redis_metric_samples', ['id' => $fresh->id]);
    }

    // 验证保留天数下限保护：--days=1 过小被判非法，命令失败且不删除任何数据。
    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->sampleDaysAgo(45);

        // days=1 低于允许下限 → 命令失败，避免误删近期数据。
        $this->artisan('ops:redis-metrics:prune', ['--days' => 1])->assertFailed();

        $this->assertDatabaseHas('ops_redis_metric_samples', ['id' => $old->id]);
    }

    // 造一条 captured_at 为 N 天前的 Redis 样本；create 后用 forceFill 回写 captured_at 锁定时间戳。
    private function sampleDaysAgo(int $days): OpsRedisMetricSample
    {
        $at = Carbon::now()->subDays($days);
        $sample = OpsRedisMetricSample::query()->create([
            'ops' => 100,
            'clients' => 5,
            'memory_mb' => 10,
            'hit_rate' => 90,
            'captured_at' => $at,
        ]);
        // forceFill 直接回写 captured_at，绕过 mutator/timestamps，锁定"N 天前"这个时间点。
        $sample->forceFill(['captured_at' => $at])->save();

        return $sample;
    }
}
