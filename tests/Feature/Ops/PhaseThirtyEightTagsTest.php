<?php

namespace Tests\Feature\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirtyEightTagsTest extends TestCase
{
    use RefreshDatabase;

    private function alert(string $source = 'disk', array $tags = []): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1($source.uniqid()),
            'source' => $source, 'severity' => 'warning', 'title' => 't', 'message' => 'm',
            'tags' => $tags, 'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_set_tags_endpoint(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();

        $this->postJson("/api/ops/alerts/{$alert->id}/tags", ['tags' => ['team:dba', 'env:prod', 'team:dba']])
            ->assertOk()
            ->assertJsonPath('data.tags', ['team:dba', 'env:prod']);

        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $alert->id, 'action' => 'tags_updated']);
    }

    public function test_filter_by_tag(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $this->alert('disk', ['team:dba']);
        $this->alert('queue', ['team:backend']);

        $items = $this->getJson('/api/ops/alerts?tag=team:dba')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('disk', $items[0]['source']);
        $this->assertContains('team:dba', $items[0]['tags']);
    }

    public function test_auto_tag_from_config_on_store(): void
    {
        config()->set('ops.alerts.auto_tags', ['disk' => ['team:infra', 'env:prod']]);

        // 走 storeAlert：用 raiseInspectionAlert 之类需要复杂 setup，这里直接验证 setTags 覆盖 + auto-tag 合并逻辑通过 demoScenarios 或 evaluate 较重；
        // 改为直接断言配置合并：新建后手动触发 store 逻辑经 evaluate 不易，故用 reflection 最小验证 auto_tags 生效路径。
        $svc = app(AlertCenterService::class);
        $method = new \ReflectionMethod($svc, 'storeAlert');
        $method->setAccessible(true);
        $dto = new AlertDTO('disk', 'warning', 'disk full', 'msg', ['target' => '/']);
        [$alert] = $method->invoke($svc, $dto);

        $this->assertContains('team:infra', $alert->fresh()->tags);
        $this->assertContains('env:prod', $alert->fresh()->tags);
    }

    public function test_set_tags_requires_manage(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view']);
        $alert = $this->alert();
        $this->postJson("/api/ops/alerts/{$alert->id}/tags", ['tags' => ['x']])->assertStatus(403);
    }

    public function test_invalid_tag_rejected(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);
        $alert = $this->alert();
        $this->postJson("/api/ops/alerts/{$alert->id}/tags", ['tags' => ['bad tag!']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags.0']);
    }
}
