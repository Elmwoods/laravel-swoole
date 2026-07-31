<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 39「外部告警入站（Ingest Webhook）」测试。
 *
 * 覆盖 /api/ingest/alerts 入站接口：功能开关（关闭返回 404）、token 鉴权
 *（query token 或 Bearer 头，错误/缺失返回 401）、有效请求创建告警并写 ingested 事件、
 * 触发外发通知、相同 dedup_key 去重复用同一行并累加 hit_count、非法 severity 返回 422。
 */
class PhaseThirtyNineIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 关闭所有外发通道，避免入站创建告警时误发真实通知（个别用例再单独打开 webhook）。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        // 打开入站功能并设置校验 token，作为鉴权用例的基准配置。
        config()->set('ops.alerts.ingest.enabled', true);
        config()->set('ops.alerts.ingest.token', 'ingest-tok');
        // 拦截未 fake 的真实外发请求。
        Http::preventStrayRequests();
    }

    // 构造入站请求体基底，允许 overrides 覆盖个别字段；dedup_key 用于去重。
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source' => 'external',
            'severity' => 'warning',
            'title' => 'CI build failed',
            'message' => 'pipeline #42 failed',
            'dedup_key' => 'ci:42',
        ], $overrides);
    }

    // 验证：入站功能关闭时，即便带正确 token，接口也返回 404（对外不暴露入口）。
    public function test_disabled_returns_404(): void
    {
        // 覆盖 setUp 的开关，关闭入站功能。
        config()->set('ops.alerts.ingest.enabled', false);
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload())->assertStatus(404);
    }

    // 验证：缺 token 或 token 错误都返回 401。
    public function test_wrong_or_missing_token_401(): void
    {
        $this->postJson('/api/ingest/alerts', $this->payload())->assertStatus(401);
        $this->postJson('/api/ingest/alerts?token=nope', $this->payload())->assertStatus(401);
    }

    // 验证：有效 token 的请求创建告警（source/severity/tags 落库）、写入 ingested 事件，并触发 webhook 外发通知。
    public function test_valid_token_creates_alert_and_notifies(): void
    {
        // 打开 webhook 通道并 fake 其响应，用来验证入站告警确实触发了外发通知。
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload(['tags' => ['team:ci']]))
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $alert = OpsAlert::query()->firstOrFail();
        $this->assertSame('external', $alert->source);
        $this->assertSame('warning', $alert->severity);
        $this->assertContains('team:ci', $alert->tags);
        $this->assertDatabaseHas('ops_alert_events', ['alert_id' => $alert->id, 'action' => 'ingested']);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    // 验证：相同 dedup_key 的两次入站不新建行，而是复用同一告警并把 hit_count 累加到 2。
    public function test_same_dedup_key_reuses_row(): void
    {
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload())->assertOk();
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload())->assertOk();

        $this->assertSame(1, OpsAlert::query()->count());
        $this->assertSame(2, (int) OpsAlert::query()->firstOrFail()->hit_count);
    }

    // 验证：token 也可通过 Authorization: Bearer 头传入（而非仅 query 参数）。
    public function test_bearer_token_accepted(): void
    {
        $this->postJson('/api/ingest/alerts', $this->payload(), ['Authorization' => 'Bearer ingest-tok'])->assertOk();
    }

    // 验证：severity 传入非法枚举值（boom）触发 422 校验错误。
    public function test_invalid_severity_422(): void
    {
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload(['severity' => 'boom']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);
    }
}
