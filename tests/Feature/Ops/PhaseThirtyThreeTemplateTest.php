<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseThirtyThreeTemplateTest extends TestCase
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
            'fingerprint' => sha1('phase33-tpl-'.uniqid()),
            'source' => 'disk',
            'severity' => 'warning',
            'title' => '磁盘告警',
            'message' => '使用率过高',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);
    }

    public function test_custom_template_renders_for_text_channel_but_webhook_stays_structured(): void
    {
        OpsAlertSetting::setValue('message_template', '[{severity}] {title} @ {source} — {message}');

        // 文本通道（telegram）用模板。
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        // webhook 走结构化。
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        app(AlertNotificationService::class)->send($this->alert());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], '[warning] 磁盘告警 @ disk'));

        // webhook 依然是结构化 JSON（未套模板）。
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com')
            && str_contains($request->body(), '"severity"')
            && str_contains($request->body(), '"fingerprint"'));
    }

    public function test_empty_template_falls_back_to_builtin_format(): void
    {
        OpsAlertSetting::setValue('message_template', '');

        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(AlertNotificationService::class)->send($this->alert());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'Ops Center 告警：磁盘告警')
            && str_contains((string) $request['text'], '说明：使用率过高'));
    }

    public function test_unknown_placeholder_is_left_intact(): void
    {
        OpsAlertSetting::setValue('message_template', '{title} {bogus}');

        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(AlertNotificationService::class)->send($this->alert());

        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '磁盘告警 {bogus}'));
    }

    public function test_update_settings_persists_message_template(): void
    {
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        $payload = OpsAlertSetting::allValues();
        $payload['message_template'] = '[{severity}] {title}';

        $this->putJson('/api/ops/alerts/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.message_template', '[{severity}] {title}');

        $this->assertSame('[{severity}] {title}', OpsAlertSetting::value('message_template'));
    }

    public function test_settings_payload_without_template_still_passes(): void
    {
        // 回归：旧 payload（不含 message_template）仍可保存。
        $payload = OpsAlertSetting::allValues();
        unset($payload['message_template']);

        $result = app(AlertCenterService::class)->updateSettings($payload);

        $this->assertArrayHasKey('message_template', $result);
    }
}
