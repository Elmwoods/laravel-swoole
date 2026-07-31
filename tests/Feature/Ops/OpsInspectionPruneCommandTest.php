<?php

namespace Tests\Feature\Ops;

use App\Models\OpsInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ops:inspections:prune 命令测试。
 *
 * 该命令按保留天数（--days）清理历史巡检记录（OpsInspection）。
 * 覆盖场景：--dry-run 只统计不删除、正常清理仅删除超期记录而保留期内记录、
 * 以及非法保留天数时命令失败且不删除任何数据。
 */
class OpsInspectionPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证 --dry-run 只报告数量而不实际删除：
     * 造一条 40 天前的巡检记录，以 14 天保留期预演后，记录仍存在。
     */
    public function test_dry_run_reports_count_without_deleting(): void
    {
        // 40 天前的记录，超出 14 天保留期；dry-run 下应保留
        $old = $this->inspectionCreatedDaysAgo(40);

        $this->artisan('ops:inspections:prune', ['--days' => 14, '--dry-run' => true])
            ->assertSuccessful();

        // 预演不落库，超期记录仍在
        $this->assertDatabaseHas('ops_inspections', ['id' => $old->id]);
    }

    /**
     * 验证正常清理只删除早于保留期的记录：
     * 40 天前的记录被删除，3 天前的记录（保留期内）保留。
     */
    public function test_prune_deletes_records_older_than_retention(): void
    {
        // old 超期应删除；fresh 在 14 天保留期内应保留
        $old = $this->inspectionCreatedDaysAgo(40);
        $fresh = $this->inspectionCreatedDaysAgo(3);

        $this->artisan('ops:inspections:prune', ['--days' => 14])
            ->assertSuccessful();

        $this->assertDatabaseMissing('ops_inspections', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_inspections', ['id' => $fresh->id]);
    }

    /**
     * 验证非法保留天数（过小）时命令失败且不删除：
     * --days=1 低于允许下限，命令应失败退出，超期记录仍保留。
     */
    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->inspectionCreatedDaysAgo(40);

        // days=1 低于最小保留天数，触发参数校验失败
        $this->artisan('ops:inspections:prune', ['--days' => 1])
            ->assertFailed();

        // 校验失败时不执行任何删除
        $this->assertDatabaseHas('ops_inspections', ['id' => $old->id]);
    }

    /**
     * 测试辅助方法：创建一条 $days 天前的巡检记录。
     * summary 记录 pass/warn/fail 统计、checks 为空数组、状态为 pass；
     * 由于 created_at 自动为当前时间，最后用 forceFill 强制改写为过去时间以模拟历史记录。
     */
    private function inspectionCreatedDaysAgo(int $days): OpsInspection
    {
        $record = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'pass',
            'summary' => ['pass' => 1, 'warn' => 0, 'fail' => 0],
            'checks' => [],
            'duration_ms' => 5,
            'started_at' => now()->subDays($days),
            'finished_at' => now()->subDays($days),
        ]);

        // 强制回填 created_at 为过去时间点，使清理命令按其判定是否超期
        $record->forceFill(['created_at' => now()->subDays($days)])->save();

        return $record;
    }
}
