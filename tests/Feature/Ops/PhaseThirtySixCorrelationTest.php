<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 36 - 告警关联抑制（correlation suppression）测试。
 *
 * 场景：定义来源之间的依赖关系（如 queue 依赖 mysql），当上游"父"来源已有告警在燃时，
 * 下游"子"来源的告警被判定为衍生噪声而抑制外发，避免级联告警刷屏。本文件覆盖：
 *   - 父来源(mysql)有 open 告警时，子来源(queue)告警被抑制（reason=suppressed）；
 *   - 无父告警时子告警正常外发；
 *   - 关联功能关闭时不抑制；
 *   - 父告警已 resolved（非燃烧）时不抑制。
 */
class PhaseThirtySixCorrelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 关闭所有通道，默认不外发。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        // 开启关联抑制，并声明依赖：queue 依赖 mysql（mysql 为父，queue 为子）。
        config()->set('ops.alerts.correlation.enabled', true);
        config()->set('ops.alerts.correlation.dependencies', ['queue' => ['mysql']]);

        Http::preventStrayRequests();
    }

    // 打开 webhook 通道并 fake 响应，供验证外发的用例使用。
    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    // 构造一条待发送的子告警（来源 queue，尚未入库），用于送入 send() 观察抑制判定。
    private function childAlert(): OpsAlert
    {
        return new OpsAlert([
            'source' => 'queue',
            'severity' => 'warning',
            'title' => 'queue backlog',
            'message' => 'm',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    // 验证：父来源 mysql 有 open 告警在燃时，子来源 queue 的告警被抑制（reason=suppressed），不外发。
    public function test_child_suppressed_when_parent_firing(): void
    {
        $this->enableWebhook();
        // 预置一条 open 的父告警(mysql) → 触发对子告警的关联抑制。
        OpsAlert::query()->create([
            'fingerprint' => sha1('mysql-open'),
            'source' => 'mysql', 'severity' => 'critical', 'title' => 'mysql down', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($this->childAlert());

        $this->assertSame('suppressed', $result['webhook']['reason']);
        Http::assertNothingSent();
    }

    // 验证：没有任何父告警在燃时，子告警(queue)正常外发到 webhook。
    public function test_child_sent_when_no_parent_firing(): void
    {
        $this->enableWebhook();

        $result = app(AlertNotificationService::class)->send($this->childAlert());

        $this->assertTrue($result['webhook']['sent']);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    // 验证：关联抑制总开关关闭时，即使父来源(mysql)有 open 告警，子告警也照常外发。
    public function test_disabled_correlation_does_not_suppress(): void
    {
        // 关闭关联抑制开关。
        config()->set('ops.alerts.correlation.enabled', false);
        $this->enableWebhook();
        OpsAlert::query()->create([
            'fingerprint' => sha1('mysql-open2'),
            'source' => 'mysql', 'severity' => 'critical', 'title' => 'mysql down', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($this->childAlert());
        $this->assertTrue($result['webhook']['sent']);
    }

    // 验证：父来源(mysql)的告警已 resolved（不再燃烧）时，不构成抑制条件，子告警正常外发。
    public function test_resolved_parent_does_not_suppress(): void
    {
        $this->enableWebhook();
        // 父告警状态为 resolved → 关联抑制只看"在燃"的父告警，故不抑制。
        OpsAlert::query()->create([
            'fingerprint' => sha1('mysql-resolved'),
            'source' => 'mysql', 'severity' => 'critical', 'title' => 'mysql', 'message' => 'm',
            'status' => 'resolved', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($this->childAlert());
        $this->assertTrue($result['webhook']['sent']);
    }
}
