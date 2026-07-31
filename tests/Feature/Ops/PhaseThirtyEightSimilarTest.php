<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 38：相似历史告警（Similar Alerts）功能测试。
 *
 * 场景：查看某条告警时，系统推荐同来源、已解决的历史告警及其处置备注，
 * 帮助值班参考过往处理经验。本测试文件覆盖：只返回同来源且 resolved 的历史
 * （携带确认人与备注）、无相似时返回空、以及接口需要 ops.alerts.view 权限。
 */
class PhaseThirtyEightSimilarTest extends TestCase
{
    use RefreshDatabase;

    // 辅助：造一条告警；当 status 为 resolved 时附带确认人 alice 与确认备注，用于验证相似项返回处置信息。
    private function alert(string $source, string $status, string $title = 't'): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.$status.$title.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => $title, 'message' => 'm',
            'status' => $status, 'hit_count' => 1, 'last_seen_at' => now(),
            'acknowledged_by' => $status === 'resolved' ? 'alice' : null,
            'acknowledge_note' => $status === 'resolved' ? '重启服务' : null,
        ]);
    }

    /**
     * 验证：相似接口只返回“同来源 + 已解决”的历史告警，并附带其处置备注；
     * 不同来源、以及未解决的告警都应被过滤掉。
     */
    public function test_similar_returns_same_source_resolved_with_notes(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);

        $current = $this->alert('disk', 'open');   // 当前查看的告警（disk 来源）
        $past = $this->alert('disk', 'resolved');   // 期望被推荐：同来源且已解决
        // 为历史告警补一条备注，验证相似项会带出 notes。
        OpsAlertNote::query()->create(['alert_id' => $past->id, 'author' => 'bob', 'body' => '清了日志']);
        $this->alert('queue', 'resolved'); // 不同来源
        $this->alert('disk', 'open');      // 非 resolved

        $items = $this->getJson("/api/ops/alerts/{$current->id}/similar")->assertOk()->json('data.items');

        // 只应命中那一条同来源、已解决的历史。
        $this->assertCount(1, $items);
        $this->assertSame($past->id, $items[0]['id']);
        $this->assertSame('alice', $items[0]['acknowledged_by']);
        // 附带的备注应完整返回。
        $this->assertCount(1, $items[0]['notes']);
        $this->assertSame('清了日志', $items[0]['notes'][0]['body']);
    }

    // 验证：当没有任何同来源、已解决的历史时，相似项返回空数组。
    public function test_empty_when_no_similar(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $alert = $this->alert('disk', 'open');
        $this->getJson("/api/ops/alerts/{$alert->id}/similar")->assertOk()->assertJsonPath('data.items', []);
    }

    // 验证：无任何权限时访问相似接口返回 403。
    public function test_requires_view_permission(): void
    {
        // 故意不授予任何权限，确认接口受 ops.alerts.view 保护。
        $this->actingAsAdminWithPermissions([]);
        $alert = $this->alert('disk', 'open');
        $this->getJson("/api/ops/alerts/{$alert->id}/similar")->assertStatus(403);
    }
}
