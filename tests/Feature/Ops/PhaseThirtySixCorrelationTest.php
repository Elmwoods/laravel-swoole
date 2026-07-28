<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtySixCorrelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        config()->set('ops.alerts.correlation.enabled', true);
        config()->set('ops.alerts.correlation.dependencies', ['queue' => ['mysql']]);

        Http::preventStrayRequests();
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

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

    public function test_child_suppressed_when_parent_firing(): void
    {
        $this->enableWebhook();
        OpsAlert::query()->create([
            'fingerprint' => sha1('mysql-open'),
            'source' => 'mysql', 'severity' => 'critical', 'title' => 'mysql down', 'message' => 'm',
            'status' => 'open', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($this->childAlert());

        $this->assertSame('suppressed', $result['webhook']['reason']);
        Http::assertNothingSent();
    }

    public function test_child_sent_when_no_parent_firing(): void
    {
        $this->enableWebhook();

        $result = app(AlertNotificationService::class)->send($this->childAlert());

        $this->assertTrue($result['webhook']['sent']);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.example.com'));
    }

    public function test_disabled_correlation_does_not_suppress(): void
    {
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

    public function test_resolved_parent_does_not_suppress(): void
    {
        $this->enableWebhook();
        OpsAlert::query()->create([
            'fingerprint' => sha1('mysql-resolved'),
            'source' => 'mysql', 'severity' => 'critical', 'title' => 'mysql', 'message' => 'm',
            'status' => 'resolved', 'hit_count' => 1, 'last_seen_at' => now(),
        ]);

        $result = app(AlertNotificationService::class)->send($this->childAlert());
        $this->assertTrue($result['webhook']['sent']);
    }
}
