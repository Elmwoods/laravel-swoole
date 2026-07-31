<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 34「按通道分模板」测试。
 *
 * 覆盖告警通知的消息模板分级：全局模板 message_template 与各通道专属模板
 * message_template_{channel}（telegram/mail/dingtalk/feishu）。验证专属模板优先于全局、
 * 全部为空时回退到内置模板、设置接口能持久化专属模板，以及 allValues() 暴露的模板键集合
 * （webhook 属结构化通道，不含文本模板）。
 */
class PhaseThirtyFourPerChannelTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 先禁用全部通道，各用例按需精确打开要验证的通道，避免串扰。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        // 拦截未 fake 的真实外发请求。
        Http::preventStrayRequests();
    }

    // 造一条 warning/open 告警作为通知发送的输入；标题"磁盘告警"用于断言渲染后的文本。
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

    // 验证：telegram 配了专属模板则用专属模板，dingtalk 没配则回退全局模板；两条通知各自渲染出对应前缀。
    public function test_per_channel_template_overrides_global(): void
    {
        // 全局模板 [G]{title}；telegram 专属模板 [TG]{title}@{source}，占位符会被告警字段替换。
        OpsAlertSetting::setValue('message_template', '[G]{title}');
        OpsAlertSetting::setValue('message_template_telegram', '[TG]{title}@{source}');

        // 同时启用 telegram 与 dingtalk 两个通道，以对比"有专属模板"与"回退全局"两种路径。
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

    // 验证：全局与专属模板都未设置时，回退到内置默认模板（"Ops Center 告警：{标题}"）。
    public function test_empty_templates_fall_back_to_builtin(): void
    {
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'tok');
        config()->set('ops.alerts.telegram.chat_id', '123');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        app(AlertNotificationService::class)->send($this->alert());

        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Ops Center 告警：磁盘告警'));
    }

    // 验证：通过设置接口提交 feishu 专属模板能被持久化，接口回显与后续读取都一致。
    public function test_update_settings_persists_per_channel_template(): void
    {
        // 更新设置属写操作，需 view+manage 权限。
        $this->actingAsAdminWithPermissions(['ops.alerts.view', 'ops.alerts.manage']);

        // 以现有全部设置为基底，只追加 feishu 专属模板，避免漏字段导致校验失败。
        $payload = OpsAlertSetting::allValues();
        $payload['message_template_feishu'] = '[FS]{title}';

        $this->putJson('/api/ops/alerts/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.message_template_feishu', '[FS]{title}');

        $this->assertSame('[FS]{title}', OpsAlertSetting::value('message_template_feishu'));
    }

    // 验证：allValues() 为每个文本通道（telegram/mail/dingtalk/feishu）暴露 message_template_ 键，
    // 而结构化通道 webhook 不含文本模板键。
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
