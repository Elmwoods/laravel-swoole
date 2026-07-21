<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class PhaseFourteenNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 让测试与本机 .env 的通道配置无关：默认关闭所有通道，各用例只开自己那一个。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        Http::preventStrayRequests();
    }

    public function test_webhook_channel_posts_signed_json_payload(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        config()->set('ops.alerts.webhook.secret', 'shh');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

        $result = app(AlertNotificationService::class)->send($this->alert());

        $this->assertTrue($result['webhook']['sent']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com/ops')
            && $request->hasHeader('X-Ops-Signature')
            && str_contains($request->body(), '"severity"'));
    }

    public function test_dingtalk_channel_signs_url_and_sends_text(): void
    {
        config()->set('ops.alerts.dingtalk.enabled', true);
        config()->set('ops.alerts.dingtalk.webhook', 'https://oapi.dingtalk.com/robot/send?access_token=abc');
        config()->set('ops.alerts.dingtalk.secret', 'ss');
        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 0], 200)]);

        $result = app(AlertNotificationService::class)->send($this->alert());

        $this->assertTrue($result['dingtalk']['sent']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'timestamp=')
            && str_contains($request->url(), 'sign=')
            && str_contains($request->body(), 'msgtype'));
    }

    public function test_feishu_channel_signs_body_and_sends_text(): void
    {
        config()->set('ops.alerts.feishu.enabled', true);
        config()->set('ops.alerts.feishu.webhook', 'https://open.feishu.cn/open-apis/bot/v2/hook/xyz');
        config()->set('ops.alerts.feishu.secret', 'ff');
        Http::fake(['open.feishu.cn/*' => Http::response(['code' => 0], 200)]);

        $result = app(AlertNotificationService::class)->send($this->alert());

        $this->assertTrue($result['feishu']['sent']);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'msg_type')
            && str_contains($request->body(), 'sign')
            && str_contains($request->body(), 'timestamp'));
    }

    public function test_status_reports_new_channels_without_leaking_credentials(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', '');
        config()->set('ops.alerts.dingtalk.enabled', true);
        config()->set('ops.alerts.dingtalk.webhook', 'https://oapi.dingtalk.com/robot/send?access_token=abc');
        config()->set('ops.alerts.dingtalk.secret', 'top-secret');

        $status = app(AlertNotificationService::class)->status();

        $this->assertArrayHasKey('webhook', $status);
        $this->assertArrayHasKey('dingtalk', $status);
        $this->assertArrayHasKey('feishu', $status);
        $this->assertFalse($status['webhook']['configured']);
        $this->assertContains('url', $status['webhook']['missing']);
        $this->assertTrue($status['dingtalk']['configured']);
        $this->assertStringNotContainsString('top-secret', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function test_send_test_covers_new_channels(): void
    {
        $result = app(AlertNotificationService::class)->sendTest(['webhook', 'dingtalk', 'feishu']);

        $this->assertArrayHasKey('webhook', $result);
        $this->assertArrayHasKey('dingtalk', $result);
        $this->assertArrayHasKey('feishu', $result);
    }

    public function test_severity_matrix_disables_a_new_channel(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        OpsAlertSetting::setValue('severity_channels', [
            'critical' => ['webhook' => true],
            'warning' => ['webhook' => false],
            'info' => ['webhook' => true],
        ]);
        Http::fake();

        $result = app(AlertNotificationService::class)->send($this->alert());

        $this->assertFalse($result['webhook']['sent']);
        $this->assertSame('channel_disabled_by_policy', $result['webhook']['reason']);
        Http::assertNothingSent();
    }

    public function test_safe_exception_message_scrubs_channel_secrets(): void
    {
        $service = app(AlertNotificationService::class);
        $method = new ReflectionMethod($service, 'safeExceptionMessage');
        $method->setAccessible(true);

        $out = $method->invoke($service, new \Exception(
            'post failed https://oapi.dingtalk.com/robot/send?access_token=SECRETTOKEN&sign=ABC123 secret=raw',
        ));

        $this->assertStringNotContainsString('SECRETTOKEN', $out);
        $this->assertStringNotContainsString('ABC123', $out);
        $this->assertStringNotContainsString('raw', $out);
    }

    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('phase14-'.uniqid()),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => 'Disk warning',
            'message' => 'Disk usage warning',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }
}
