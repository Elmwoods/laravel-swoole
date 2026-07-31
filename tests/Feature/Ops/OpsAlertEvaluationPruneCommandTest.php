<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlertEvaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ops:alerts:prune-evaluations 命令测试。
 *
 * 该命令按保留天数（--days）清理历史告警评估记录（OpsAlertEvaluation）。
 * 覆盖场景：--dry-run 只报告不删除、正常清理仅删除超期记录而保留期内记录、
 * 以及非法保留天数时命令失败且不删除任何数据。
 */
class OpsAlertEvaluationPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证 --dry-run 只报告将删除的数量而不实际删除：
     * 造一条 60 天前的记录，以 30 天保留期预演后，该记录仍存在。
     */
    public function test_dry_run_reports_without_deleting(): void
    {
        // 60 天前的记录，超出 30 天保留期，本会被删——但 dry-run 下应保留
        $old = $this->evaluationCreatedDaysAgo(60);

        $this->artisan('ops:alerts:prune-evaluations', ['--days' => 30, '--dry-run' => true])
            ->assertSuccessful();

        // 预演不落库，超期记录仍在
        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $old->id]);
    }

    /**
     * 验证正常清理只删除早于保留期的记录：
     * 60 天前的记录被删除，5 天前的记录（保留期内）保留。
     */
    public function test_prune_deletes_records_older_than_retention(): void
    {
        // old 超出 30 天保留期，应被删除；fresh 在保留期内，应保留
        $old = $this->evaluationCreatedDaysAgo(60);
        $fresh = $this->evaluationCreatedDaysAgo(5);

        $this->artisan('ops:alerts:prune-evaluations', ['--days' => 30])
            ->assertSuccessful();

        $this->assertDatabaseMissing('ops_alert_evaluations', ['id' => $old->id]);
        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $fresh->id]);
    }

    /**
     * 验证非法保留天数（过小）时命令失败且不删除：
     * --days=1 低于允许下限，命令应失败退出，超期记录不受影响仍保留。
     */
    public function test_invalid_retention_days_fails_without_deleting(): void
    {
        $old = $this->evaluationCreatedDaysAgo(60);

        // days=1 低于最小保留天数，触发参数校验失败
        $this->artisan('ops:alerts:prune-evaluations', ['--days' => 1])
            ->assertFailed();

        // 校验失败时不执行任何删除
        $this->assertDatabaseHas('ops_alert_evaluations', ['id' => $old->id]);
    }

    /**
     * 测试辅助方法：创建一条 $days 天前的告警评估记录。
     * 由于 created_at 由框架自动填充为当前时间，这里用 forceFill 强制改写为过去时间，
     * 以便模拟"超期"或"保留期内"的记录用于清理测试。
     */
    private function evaluationCreatedDaysAgo(int $days): OpsAlertEvaluation
    {
        $at = Carbon::now()->subDays($days);
        $record = OpsAlertEvaluation::query()->create([
            'trigger' => 'schedule',
            'status' => 'success',
            'detected_count' => 0,
            'auto_resolved_count' => 0,
            'started_at' => $at,
            'finished_at' => $at,
            'duration_ms' => 5,
            'message' => null,
        ]);
        // 强制回填 created_at 为过去时间点，使清理命令按其判定是否超期
        $record->forceFill(['created_at' => $at])->save();

        return $record;
    }
}
