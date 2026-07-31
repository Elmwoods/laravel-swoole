<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 37 - 告警处置手册（runbook）测试。
 *
 * 场景：可为每个告警来源在配置 ops.alerts.runbooks 中挂接一份处置手册（链接 + 步骤列表），
 * 序列化告警时按来源注入对应 runbook，帮助值班人快速处置。本文件覆盖：
 *   - 已配置来源的告警序列化后带 runbook（空步骤被过滤）；
 *   - 未配置来源的告警 runbook 为 null；
 *   - 列表端点也会暴露 runbook 字段。
 */
class PhaseThirtySevenRunbookTest extends TestCase
{
    use RefreshDatabase;

    // 按指定来源构造一条 open 告警。
    private function alert(string $source): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    // 验证：来源已在 runbooks 配置中时，serialize() 注入对应 url 与 steps，且空字符串步骤被过滤掉。
    public function test_serialize_includes_runbook_for_configured_source(): void
    {
        // 为 disk 来源配置 runbook；steps 中末尾故意留一个空串以验证过滤。
        config()->set('ops.alerts.runbooks', [
            'disk' => ['url' => 'https://rb.example.com/disk', 'steps' => ['清理日志', '扩容磁盘', '']],
        ]);

        $data = app(AlertCenterService::class)->serialize($this->alert('disk'));

        $this->assertSame('https://rb.example.com/disk', $data['runbook']['url']);
        $this->assertSame(['清理日志', '扩容磁盘'], $data['runbook']['steps']); // 空步骤被过滤
    }

    // 验证：仅为 disk 配置了 runbook，而告警来源是 queue（未配置）时，序列化结果的 runbook 为 null。
    public function test_unconfigured_source_has_null_runbook(): void
    {
        config()->set('ops.alerts.runbooks', ['disk' => ['url' => 'x', 'steps' => []]]);

        $data = app(AlertCenterService::class)->serialize($this->alert('queue'));

        $this->assertNull($data['runbook']);
    }

    // 验证：告警列表端点 /api/ops/alerts 也会带出 runbook 字段（与 serialize 一致）。需 view 权限。
    public function test_list_endpoint_exposes_runbook(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        config()->set('ops.alerts.runbooks', ['disk' => ['url' => 'https://rb/disk', 'steps' => ['a']]]);
        $this->alert('disk');

        $items = $this->getJson('/api/ops/alerts')->assertOk()->json('data.items');
        $this->assertSame('https://rb/disk', $items[0]['runbook']['url']);
    }
}
