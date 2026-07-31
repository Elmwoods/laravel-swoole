<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 37 - 告警热力图（heatmap）分析测试。
 *
 * 场景：按"星期几 × 小时"（7×24 = 168 个桶）统计一段时间窗内的告警数量，并给出
 * "最吵来源"排行。用于运维定位告警高发的时段/来源。本文件覆盖：
 *   - 桶按 dayOfWeek 与 hour 正确归集计数，来源按总量降序排列；
 *   - 无数据时返回全零的 168 桶矩阵；
 *   - HTTP 端点返回结构与 ops.alerts.view 权限校验。
 */
class PhaseThirtySevenHeatmapTest extends TestCase
{
    use RefreshDatabase;

    // 在指定来源与时刻创建一条告警；用 forceFill 强制回填 created_at，
    // 因为热力图按创建时间落桶，需要精确控制时间而非默认的 now()。
    private function alertAt(string $source, Carbon $at): void
    {
        $alert = OpsAlert::query()->create([
            'fingerprint' => sha1($source.$at->timestamp.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => $at,
        ]);
        $alert->forceFill(['created_at' => $at])->save();
    }

    // 验证：桶按"星期几(dow) × 小时(hour)"归集计数，且来源排行按总量降序。
    // 造 2 条周三 14 点的 disk 告警与 1 条周一 9 点的 queue 告警，
    // 检查对应桶计数、总桶数 168、以及 disk 作为最吵来源排第一。
    public function test_buckets_count_by_hour_and_dow(): void
    {
        // 2026-07-29 是周三（dayOfWeek=3），14:00。
        $this->alertAt('disk', Carbon::parse('2026-07-29 14:00:00'));
        $this->alertAt('disk', Carbon::parse('2026-07-29 14:30:00'));
        $this->alertAt('queue', Carbon::parse('2026-07-27 09:00:00')); // 周一 dow=1

        $summary = app(AlertCenterService::class)->heatmapSummary(30);

        $cell = collect($summary['buckets'])->firstWhere(fn ($b) => $b['dow'] === 3 && $b['hour'] === 14);
        $this->assertSame(2, $cell['count']);
        $mon = collect($summary['buckets'])->firstWhere(fn ($b) => $b['dow'] === 1 && $b['hour'] === 9);
        $this->assertSame(1, $mon['count']);

        // 7×24 = 168 桶。
        $this->assertCount(168, $summary['buckets']);
        // 最吵来源 disk(2) 在前。
        $this->assertSame('disk', $summary['sources'][0]['source']);
        $this->assertSame(2, $summary['sources'][0]['total']);
    }

    // 验证：没有任何告警数据时，仍返回完整的 168 桶矩阵，全部计数为 0，来源列表为空。
    public function test_empty_returns_zero_matrix(): void
    {
        $summary = app(AlertCenterService::class)->heatmapSummary(7);
        $this->assertCount(168, $summary['buckets']);
        $this->assertSame(0, collect($summary['buckets'])->sum('count'));
        $this->assertSame([], $summary['sources']);
    }

    // 验证：/api/ops/alerts/heatmap 端点返回 200，且 days 透传、buckets 恒为 168 个。
    // 需 ops.alerts.view 权限（查看类接口）。
    public function test_endpoint(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alertAt('disk', now());

        $this->getJson('/api/ops/alerts/heatmap?days=30')
            ->assertOk()
            ->assertJsonPath('data.days', 30)
            ->assertJsonCount(168, 'data.buckets');
    }

    // 验证：无任何权限的管理员访问热力图端点被拒（403），确认权限门生效。
    public function test_endpoint_requires_view(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/ops/alerts/heatmap')->assertStatus(403);
    }
}
