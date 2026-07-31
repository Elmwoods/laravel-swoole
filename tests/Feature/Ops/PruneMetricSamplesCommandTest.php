<?php

namespace Tests\Feature\Ops;

use App\Models\OpsMetricSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ops:metrics:prune 命令测试：按保留天数清理系统指标采样表（ops_metric_samples）。
 *
 * 覆盖三种行为：dry-run 只报告不删、正式运行只删超过保留期的旧样本、
 * 保留天数过小（不合法）时命令失败且不删任何数据（防误删的安全下限）。
 */
class PruneMetricSamplesCommandTest extends TestCase
{
    use RefreshDatabase;

    // 验证 dry-run：预演清理时命令成功返回，但 45 天前的旧样本仍在库中未被删除。
    public function test_dry_run_reports_without_deleting(): void
    {
        // 造一条 45 天前的样本，本应被 30 天保留期清理，但 dry-run 下应保留。
        $old = $this->sampleDaysAgo(45);

        $this->artisan('ops:metrics:prune', ['--days' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('ops_metric_samples', ['id' => $old->id]);
    }

    // 验证正式清理只删超过保留期的样本：45 天前的被删，10 天前的保留。
    public function test_prune_deletes_only_samples_older_than_retention(): void
    {
        $old = $this->sampleDaysAgo(45);   // 超过 30 天保留期 → 应删
        $fresh = $this->sampleDaysAgo(10); // 在保留期内 → 应留

        $this->artisan('ops:metrics:prune', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('ops_metric_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_metric_samples', ['id' => $fresh->id]);
    }

    // 验证保留天数下限保护：--days=1 过小被判非法，命令失败且不删除任何数据。
    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->sampleDaysAgo(45);

        // days=1 低于允许下限 → 命令失败，避免误删近期数据。
        $this->artisan('ops:metrics:prune', ['--days' => 1])->assertFailed();

        $this->assertDatabaseHas('ops_metric_samples', ['id' => $old->id]);
    }

    // 造一条 captured_at 为 N 天前的样本；create 后再用 forceFill 回写 captured_at，确保时间戳不被模型改写。
    private function sampleDaysAgo(int $days): OpsMetricSample
    {
        $at = Carbon::now()->subDays($days);
        $sample = OpsMetricSample::query()->create([
            'cpu_load' => 1,
            'load1' => 1,
            'memory_used_percent' => 10,
            'swap_used_percent' => 0,
            'captured_at' => $at,
        ]);
        // forceFill 直接回写 captured_at，绕过任何 mutator/timestamps，锁定"N 天前"这个时间点。
        $sample->forceFill(['captured_at' => $at])->save();

        return $sample;
    }
}
