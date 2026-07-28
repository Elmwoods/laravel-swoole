<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtyFourPerChannelTemplateTest extends TestCase
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

    private function alert(): OpsAlert
    {
        return OpsAlert::query()->create([
            'fingerprint' => sha1('ph34-tpl-'.uniqid()),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => '磁盘告警',
            'message' => 'msg',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    public function test_per_channel_template_overrides_global(): void
    {
        OpsAlertSetting::setValue('message_template', '[G]{title}');
        OpsAlertSetting::setValue('message_template_telegram', '[TG]{title}@{source}');

        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        config()->set('ops.alerts.dingtalk.enabled', true);
        config()->set('ops.alerts.dingtalk.webhook', 'https://oapi.dingtalk.com/robot/send?access_token=abc');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'oapi.dingtalk.com/*' => Http::response(['errcode' => 0], 200),
        ]);

        app(AlertNotificationService::class)->send($this->alert());

        // telegram 用专属模板。
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], '[TG]磁盘告警@disk'));
        // dingtalk 无专属模板 → 回退全局。
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oapi.dingtalk.com')
            && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), '[G]磁盘告警'));
    }

    public function test_empty_templates_fall_back_to_builtin(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(AlertNotificationService::class)->send($this->alert());

        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Ops Center 告警：磁盘告警'));
    }

    public function test_update_settings_persists_per_channel_template(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $payload = OpsAlertSetting::allValues();
        $payload['message_template_feishu'] = '[FS]{title}';

        $this->putJson('/api/ops/alerts/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.message_template_feishu', '[FS]{title}');

        $this->assertSame('[FS]{title}', OpsAlertSetting::value('message_template_feishu'));
    }

    public function test_all_values_exposes_per_channel_keys(): void
    {
        $values = OpsAlertSetting::allValues();

        foreach (['telegram', 'mail', 'dingtalk', 'feishu'] as $channel) {
            $this->assertArrayHasKey("message_template_{$channel}", $values);
        }
        // webhook 不出现（结构化通道）。
        $this->assertArrayNotHasKey('message_template_webhook', $values);
    }
}
