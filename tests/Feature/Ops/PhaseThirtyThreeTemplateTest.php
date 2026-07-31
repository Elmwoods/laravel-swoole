<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ops Center 第 33 阶段：告警通知消息模板测试。
 *
 * 覆盖场景：管理员可在设置里自定义文本类通道（如 telegram）的消息模板（含 {severity}/{title} 等占位符），
 * 而 webhook 这类结构化通道始终发送结构化 JSON、不套模板；模板为空时回落到内置格式，
 * 未知占位符原样保留；并验证模板可持久化保存、旧 payload（不含 message_template）仍向后兼容。
 */
class PhaseThirtyThreeTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 先关闭所有告警通道，避免测试间残留配置串扰；各用例再按需单独开启。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        // 禁止任何未被 fake 的真实外发请求，防止误发到真实 telegram/webhook。
        Http::preventStrayRequests();
    }

    // 构造一条用于发送的告警样本（warning/磁盘来源），供各用例复用。
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

    /**
     * 验证自定义模板只对文本通道（telegram）生效、渲染出占位符替换后的文本，
     * 而 webhook 仍发送结构化 JSON（保留 severity/fingerprint 等字段，不套模板）。
     * 设置里写入含 {severity}/{title}/{source}/{message} 的模板，同时开启 telegram 与 webhook 两条通道。
     */
    public function test_custom_template_renders_for_text_channel_but_webhook_stays_structured(): void
    {
        // 写入自定义消息模板。
        OpsAlertSetting::setValue('message_template', '[{severity}] {title} @ {source} — {message}');

        // 文本通道（telegram）用模板。
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        // webhook 走结构化。
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');

        // 两条通道都 fake 掉，返回成功响应。
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'hooks.example.com/*' => Http::response('', 200),
        ]);

        app(AlertNotificationService::class)->send($this->alert());

        // telegram 文本应为模板渲染结果（占位符已替换成实际值）。
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], '[warning] 磁盘告警 @ disk'));

        // webhook 依然是结构化 JSON（未套模板）。
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com')
            && str_contains($request->body(), '"severity"')
            && str_contains($request->body(), '"fingerprint"'));
    }

    /**
     * 验证模板设为空字符串时，通知回落到内置默认格式（含“Ops Center 告警：”“说明：”等固定文案）。
     */
    public function test_empty_template_falls_back_to_builtin_format(): void
    {
        // 空模板 → 应走内置格式。
        OpsAlertSetting::setValue('message_template', '');

        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(AlertNotificationService::class)->send($this->alert());

        // 文本应包含内置格式的标题与说明段落。
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'Ops Center 告警：磁盘告警')
            && str_contains((string) $request['text'], '说明：使用率过高'));
    }

    /**
     * 验证模板中出现未知占位符（如 {bogus}）时原样保留，不被清空或报错。
     */
    public function test_unknown_placeholder_is_left_intact(): void
    {
        // {title} 会被替换，{bogus} 是未知占位符应原样保留。
        OpsAlertSetting::setValue('message_template', '{title} {bogus}');

        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(AlertNotificationService::class)->send($this->alert());

        // 断言 {title} 已替换为“磁盘告警”，而 {bogus} 原样保留。
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '磁盘告警 {bogus}'));
    }

    /**
     * 验证通过设置接口更新可持久化保存 message_template，且响应与落库值一致。
     */
    public function test_update_settings_persists_message_template(): void
    {
        // 更新设置需 view + manage 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        // 以当前全部设置为基底，仅覆盖 message_template 后整体提交。
        $payload = OpsAlertSetting::allValues();
        $payload['message_template'] = '[{severity}] {title}';

        $this->putJson('/api/ops/alerts/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.message_template', '[{severity}] {title}');

        // 落库值应与提交一致。
        $this->assertSame('[{severity}] {title}', OpsAlertSetting::value('message_template'));
    }

    /**
     * 回归：验证旧版 payload（不含 message_template 键）仍能保存成功，
     * 且服务层为返回结果补齐 message_template 键，保证向后兼容。
     */
    public function test_settings_payload_without_template_still_passes(): void
    {
        // 回归：旧 payload（不含 message_template）仍可保存。
        $payload = OpsAlertSetting::allValues();
        unset($payload['message_template']);

        $result = app(AlertCenterService::class)->updateSettings($payload);

        $this->assertArrayHasKey('message_template', $result);
    }
}
