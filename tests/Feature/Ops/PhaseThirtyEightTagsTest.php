<?php

namespace Tests\Feature\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 38：告警标签（Alert Tags）功能测试。
 *
 * 场景：可为告警打自定义标签（team:xxx / env:xxx 等）用于分类与过滤，
 * 并支持按来源自动打标签。本测试文件覆盖：设置标签接口（去重 + 写事件）、
 * 按标签过滤列表、按配置在入库时自动打标签、设置标签需要 manage 权限、
 * 以及非法标签格式被校验拒绝。
 */
class PhaseThirtyEightTagsTest extends TestCase
{
    use RefreshDatabase;

    // 辅助：造一条带指定来源与标签的 open 告警。
    private function alert(string $source = 'disk', array $tags = []): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'tags' => $tags, 'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    /**
     * 验证：设置标签接口会对重复标签去重（['team:dba','env:prod','team:dba'] → 两项），
     * 并写入一条 tags_updated 告警事件。
     */
    public function test_set_tags_endpoint(): void
    {
        // 设置标签属写操作，需要 manage 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();

        // 传入含重复项的标签，期望结果去重后保序为 team:dba、env:prod。
        $this->postJson("/api/ops/alerts/{$alert->id}/tags", ['tags' => ['team:dba', 'env:prod', 'team:dba']])
            ->assertOk()
            ->assertJsonPath('data.tags', ['team:dba', 'env:prod']);

        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $alert->id, 'action' => 'tags_updated']);
    }

    /**
     * 验证：列表接口可按 tag 查询参数过滤，只返回带该标签的告警。
     */
    public function test_filter_by_tag(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alert('disk', ['team:dba']);
        $this->alert('queue', ['team:backend']);

        // 按 team:dba 过滤，只应命中 disk 那条。
        $items = $this->getJson('/api/ops/alerts?tag=team:dba')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('disk', $items[0]['source']);
        $this->assertContains('team:dba', $items[0]['tags']);
    }

    /**
     * 验证：入库时根据 ops.alerts.auto_tags 配置为对应来源自动合并标签。
     */
    public function test_auto_tag_from_config_on_store(): void
    {
        // 配置：disk 来源的告警入库时自动带上 team:infra 与 env:prod。
        config()->set('ops.alerts.auto_tags', ['disk' => ['team:infra', 'env:prod']]);

        // 走 storeAlert：用 raiseInspectionAlert 之类需要复杂 setup，这里直接验证 setTags 覆盖 + auto-tag 合并逻辑通过 demoScenarios 或 evaluate 较重；
        // 改为直接断言配置合并：新建后手动触发 store 逻辑经 evaluate 不易，故用 reflection 最小验证 auto_tags 生效路径。
        // 用反射直接调用 protected 的 storeAlert，最小化触达自动打标签路径。
        $svc = app(AlertCenterService::class);
        $method = new \ReflectionMethod($svc, 'storeAlert');
        $method->setAccessible(true);
        // 构造一条 disk 来源的告警 DTO 走入库。
        $dto = new AlertDTO('disk', 'warning', 'disk full', 'msg', ['target' => '/']);
        [$alert] = $method->invoke($svc, $dto);

        // 入库后应带上配置里的自动标签。
        $this->assertContains('team:infra', $alert->fresh()->tags);
        $this->assertContains('env:prod', $alert->fresh()->tags);
    }

    // 验证：仅有 view 权限（缺 manage）时设置标签返回 403。
    public function test_set_tags_requires_manage(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $alert = $this->alert();
        $this->postJson("/api/ops/alerts/{$alert->id}/tags", ['tags' => ['x']])->assertStatus(403);
    }

    // 验证：含非法字符的标签（如带空格与感叹号的 'bad tag!'）被校验拒绝，返回 422 且指向 tags.0。
    public function test_invalid_tag_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();
        $this->postJson("/api/ops/alerts/{$alert->id}/tags", ['tags' => ['bad tag!']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags.0']);
    }
}
