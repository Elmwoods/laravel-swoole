<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PhaseNineteenAlertDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        Http::preventStrayRequests();
    }

    public function test_enabled_digest_pushes_summary_through_channel(): void
    {
        config()->set('ops.alerts.digest.enabled', true);
        $this->enableWebhook();
        $this->makeAlert('critical', 'open', 'disk');
        $this->makeAlert('warning', 'open', 'queue');

        $this->artisan('ops:alerts:digest')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com')
            && str_contains($request->body(), '告警摘要'));
    }

    public function test_disabled_digest_does_not_send(): void
    {
        config()->set('ops.alerts.digest.enabled', false);
        $this->enableWebhook();
        $this->makeAlert('critical', 'open', 'disk');

        $this->artisan('ops:alerts:digest')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_dry_run_renders_but_does_not_send(): void
    {
        config()->set('ops.alerts.digest.enabled', true);
        $this->enableWebhook();
        $this->makeAlert('critical', 'open', 'disk');

        $this->artisan('ops:alerts:digest', ['--dry-run' => true])
            ->expectsOutputToContain('摘要预览')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_empty_window_without_send_when_empty_does_not_send(): void
    {
        config()->set('ops.alerts.digest.enabled', true);
        config()->set('ops.alerts.digest.send_when_empty', false);
        $this->enableWebhook();

        $this->artisan('ops:alerts:digest')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_hours_option_overrides_window(): void
    {
        config()->set('ops.alerts.digest.enabled', true);
        config()->set('ops.alerts.digest.window_hours', 24);
        $this->enableWebhook();

        // 仅有一条 10 小时前的告警：--hours=6 时窗口内为空，不发送。
        $this->makeAlert('info', 'open', 'disk', now()->subHours(10));

        $this->artisan('ops:alerts:digest', ['--hours' => 6])->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_invalid_hours_option_fails(): void
    {
        $this->artisan('ops:alerts:digest', ['--hours' => 'abc'])
            ->assertExitCode(1);
    }

    public function test_command_is_fault_tolerant_on_service_error(): void
    {
        config()->set('ops.alerts.digest.enabled', true);

        $mock = Mockery::mock(AlertCenterService::class);
        $mock->shouldReceive('sendDigest')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(AlertCenterService::class, $mock);

        $this->artisan('ops:alerts:digest')->assertExitCode(0);
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    private function makeAlert(string $severity, string $status, string $source, ?\DateTimeInterface $createdAt = null): void
    {
        $createdAt ??= now()->subHour();

        $alert = OpsAlert::query()->create([
            'fingerprint' => $source.'-'.uniqid(),
            'source' => $source,
            'severity' => $severity,
            'title' => "{$source} alert",
            'message' => 'body',
            'status' => $status,
            'hit_count' => 1,
            'last_seen_at' => $createdAt,
        ]);

        $alert->forceFill(['created_at' => $createdAt])->save();
    }
}
