<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtyNineIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }
        config()->set('ops.alerts.ingest.enabled', true);
        config()->set('ops.alerts.ingest.token', 'ingest-tok');
        Http::preventStrayRequests();
    }

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

    public function test_disabled_returns_404(): void
    {
        config()->set('ops.alerts.ingest.enabled', false);
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload())->assertStatus(404);
    }

    public function test_wrong_or_missing_token_401(): void
    {
        $this->postJson('/api/ingest/alerts', $this->payload())->assertStatus(401);
        $this->postJson('/api/ingest/alerts?token=nope', $this->payload())->assertStatus(401);
    }

    public function test_valid_token_creates_alert_and_notifies(): void
    {
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

    public function test_same_dedup_key_reuses_row(): void
    {
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload())->assertOk();
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload())->assertOk();

        $this->assertSame(1, OpsAlert::query()->count());
        $this->assertSame(2, (int) OpsAlert::query()->firstOrFail()->hit_count);
    }

    public function test_bearer_token_accepted(): void
    {
        $this->postJson('/api/ingest/alerts', $this->payload(), ['Authorization' => 'Bearer ingest-tok'])->assertOk();
    }

    public function test_invalid_severity_422(): void
    {
        $this->postJson('/api/ingest/alerts?token=ingest-tok', $this->payload(['severity' => 'boom']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);
    }
}
