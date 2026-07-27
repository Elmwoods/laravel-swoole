<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * 告警通知服务。
 *
 * 通道单一来源于 config('ops.alerts.channels')，各处遍历该列表；未配置凭据时只记录，不阻塞采集。
 */
class AlertNotificationService
{
    public function __construct(
        private readonly AlertSilenceService $silences,
    ) {}

    /**
     * 每个通道判定 configured/missing 所需的凭据字段（config 键，相对 ops.alerts.<channel>）。
     */
    private const CHANNEL_CREDENTIALS = [
        'telegram' => ['bot_token', 'chat_id'],
        'mail' => ['to'],
        'webhook' => ['url'],
        'dingtalk' => ['webhook'],
        'feishu' => ['webhook'],
    ];

    /**
     * 支持的通知通道（单一来源）。
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return array_values((array) config('ops.alerts.channels', ['telegram', 'mail']));
    }

    /**
     * 获取通知通道配置状态。
     *
     * 只返回是否启用和是否配置完整，不返回 token、chat_id、邮箱、webhook、secret 等敏感值。
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->channels() as $channel) {
            $configEnabled = (bool) config("ops.alerts.{$channel}.enabled", false);
            $toggle = (bool) OpsAlertSetting::value("{$channel}_enabled");
            $missing = $configEnabled ? [] : ['enabled'];
            $allPresent = true;

            foreach (self::CHANNEL_CREDENTIALS[$channel] ?? [] as $credential) {
                if ($this->credentialMissing($channel, $credential)) {
                    $missing[] = $credential;
                    $allPresent = false;
                }
            }

            $status[$channel] = [
                'enabled' => $configEnabled && $toggle,
                'configured' => $configEnabled && $allPresent,
                'missing' => array_values($missing),
            ];
        }

        $status['checked_at'] = now()->toDateTimeString();

        return $status;
    }

    /**
     * 发送告警通知（遍历所有通道）。
     */
    public function send(OpsAlert $alert): array
    {
        // 值班静默窗口：命中则只入库/广播（由调用方完成），不外发到任何通道。
        if ($this->silences->isSilenced($alert)) {
            return collect($this->channels())
                ->mapWithKeys(fn (string $channel): array => [$channel => ['enabled' => true, 'sent' => false, 'reason' => 'silenced']])
                ->all();
        }

        $result = [];

        foreach ($this->channels() as $channel) {
            $result[$channel] = $this->dispatch($channel, $alert);
        }

        return $result;
    }

    /**
     * 发送测试通知（不写入告警表，仅验证通道配置）。
     */
    public function sendTest(array $channels = [], ?string $message = null): array
    {
        $supported = $this->channels();
        $channels = $channels ?: $supported;
        $channels = array_values(array_intersect($channels, $supported));

        $alert = new OpsAlert([
            'source' => 'notification-test',
            'severity' => 'info',
            'title' => 'Ops Center 通知测试',
            'message' => $message ?: '这是一条 Ops Center 告警通知测试消息。',
            'status' => 'open',
            'hit_count' => 1,
            'last_seen_at' => now(),
        ]);

        $result = [];

        foreach ($channels as $channel) {
            $result[$channel] = $this->dispatch($channel, $alert);
        }

        return $result;
    }

    private function dispatch(string $channel, OpsAlert $alert): array
    {
        return match ($channel) {
            'telegram' => $this->sendTelegram($alert),
            'mail' => $this->sendMail($alert),
            'webhook' => $this->sendWebhook($alert),
            'dingtalk' => $this->sendDingtalk($alert),
            'feishu' => $this->sendFeishu($alert),
            default => ['enabled' => false, 'sent' => false, 'reason' => 'unknown_channel'],
        };
    }

    /**
     * 连通性静默探测多个通道（不投递用户可见告警，钉钉/飞书除外——发轻量心跳）。
     *
     * @return array<string, array{healthy: bool, reason?: string, checked_via: string}>
     */
    public function probeChannels(array $channels = []): array
    {
        $supported = $this->channels();
        $channels = $channels ?: $supported;
        $channels = array_values(array_intersect($channels, $supported));

        $result = [];

        foreach ($channels as $channel) {
            $result[$channel] = $this->probeChannel($channel);
        }

        return $result;
    }

    /**
     * 探测单个通道连通性。checked_via='skipped' 表示未启用/未配置（不计入健康统计）。
     *
     * @return array{healthy: bool, reason?: string, checked_via: string}
     */
    public function probeChannel(string $channel): array
    {
        if (! (bool) config("ops.alerts.{$channel}.enabled", false)) {
            return ['healthy' => false, 'reason' => 'not_enabled', 'checked_via' => 'skipped'];
        }

        if (! (bool) OpsAlertSetting::value("{$channel}_enabled")) {
            return ['healthy' => false, 'reason' => 'disabled_by_toggle', 'checked_via' => 'skipped'];
        }

        foreach (self::CHANNEL_CREDENTIALS[$channel] ?? [] as $credential) {
            if ($this->credentialMissing($channel, $credential)) {
                return ['healthy' => false, 'reason' => "missing_{$credential}", 'checked_via' => 'skipped'];
            }
        }

        return match ($channel) {
            'telegram' => $this->probeTelegram(),
            'mail' => $this->probeMail(),
            'webhook' => $this->probeWebhook(),
            'dingtalk' => $this->probeDingtalk(),
            'feishu' => $this->probeFeishu(),
            default => ['healthy' => false, 'reason' => 'unknown_channel', 'checked_via' => 'skipped'],
        };
    }

    /**
     * 通道发送前的统一守卫：设置表可用 → 严重级策略允许 → config 已启用。
     *
     * 返回 null 表示可继续发送；否则返回应直接回传的结果。
     */
    private function guard(OpsAlert $alert, string $channel): ?array
    {
        if (! $this->settingsAvailable()) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'settings_unavailable'];
        }

        if (! $this->channelAllowed($alert, $channel)) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'channel_disabled_by_policy'];
        }

        if (! (bool) config("ops.alerts.{$channel}.enabled", false)) {
            return ['enabled' => false, 'sent' => false];
        }

        return null;
    }

    /**
     * Telegram 告警。
     */
    private function sendTelegram(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'telegram')) {
            return $guard;
        }

        $token = (string) config('ops.alerts.telegram.bot_token', '');
        $chatId = (string) config('ops.alerts.telegram.chat_id', '');

        if ($token === '' || $chatId === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'telegram_not_configured'];
        }

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->formatMessage($alert),
                'disable_web_page_preview' => true,
            ]);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('telegram', $alert, $e);
        }
    }

    /**
     * 邮件告警。
     */
    private function sendMail(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'mail')) {
            return $guard;
        }

        $to = array_values(array_filter((array) config('ops.alerts.mail.to', [])));

        if ($to === []) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'mail_recipient_empty'];
        }

        try {
            Mail::raw($this->formatMessage($alert), function ($message) use ($alert, $to): void {
                $message->to($to)->subject("[Ops Center][{$alert->severity}] {$alert->title}");
            });

            return ['enabled' => true, 'sent' => true];
        } catch (Throwable $e) {
            return $this->failure('mail', $alert, $e);
        }
    }

    /**
     * 通用 Webhook 告警：POST JSON，可选 HMAC-SHA256 签名头。
     */
    private function sendWebhook(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'webhook')) {
            return $guard;
        }

        $url = (string) config('ops.alerts.webhook.url', '');

        if ($url === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'webhook_not_configured'];
        }

        $secret = (string) config('ops.alerts.webhook.secret', '');
        $body = json_encode($this->webhookPayload($alert), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $request = Http::timeout(5)->withBody($body, 'application/json');

            if ($secret !== '') {
                $request = $request->withHeaders(['X-Ops-Signature' => hash_hmac('sha256', $body, $secret)]);
            }

            $response = $request->post($url);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('webhook', $alert, $e);
        }
    }

    /**
     * 钉钉群机器人告警：text 消息，可选加签（timestamp + sign 拼到 URL）。
     */
    private function sendDingtalk(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'dingtalk')) {
            return $guard;
        }

        $webhook = (string) config('ops.alerts.dingtalk.webhook', '');

        if ($webhook === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'dingtalk_not_configured'];
        }

        $secret = (string) config('ops.alerts.dingtalk.secret', '');
        $url = $webhook;

        if ($secret !== '') {
            $timestamp = (string) (int) round(microtime(true) * 1000);
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            $url .= (str_contains($url, '?') ? '&' : '?').'timestamp='.$timestamp.'&sign='.rawurlencode($sign);
        }

        try {
            $response = Http::timeout(5)->post($url, [
                'msgtype' => 'text',
                'text' => ['content' => $this->formatMessage($alert)],
            ]);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('dingtalk', $alert, $e);
        }
    }

    /**
     * 飞书群机器人告警：text 消息，可选加签（timestamp + sign 放入请求体）。
     */
    private function sendFeishu(OpsAlert $alert): array
    {
        if ($guard = $this->guard($alert, 'feishu')) {
            return $guard;
        }

        $webhook = (string) config('ops.alerts.feishu.webhook', '');

        if ($webhook === '') {
            return ['enabled' => true, 'sent' => false, 'reason' => 'feishu_not_configured'];
        }

        $secret = (string) config('ops.alerts.feishu.secret', '');
        $payload = [
            'msg_type' => 'text',
            'content' => ['text' => $this->formatMessage($alert)],
        ];

        if ($secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp."\n".$secret, true));
        }

        try {
            $response = Http::timeout(5)->post($webhook, $payload);

            return ['enabled' => true, 'sent' => $response->successful(), 'status' => $response->status()];
        } catch (Throwable $e) {
            return $this->failure('feishu', $alert, $e);
        }
    }

    /**
     * Telegram 静默探测：getMe（验证 token + 网络，不投递消息）。
     */
    private function probeTelegram(): array
    {
        $token = (string) config('ops.alerts.telegram.bot_token', '');

        try {
            $response = Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getMe");
            $ok = $response->successful() && (bool) data_get($response->json(), 'ok', false);

            return $ok
                ? ['healthy' => true, 'checked_via' => 'getMe']
                : ['healthy' => false, 'reason' => 'telegram_getme_status_'.$response->status(), 'checked_via' => 'getMe'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'getMe'];
        }
    }

    /**
     * 邮件静默探测：打开 SMTP 传输连接（不投递邮件）。非 SMTP 驱动视为跳过。
     */
    private function probeMail(): array
    {
        try {
            $transport = Mail::mailer()->getSymfonyTransport();

            if (! $transport instanceof SmtpTransport) {
                return ['healthy' => false, 'reason' => 'non_smtp_driver', 'checked_via' => 'skipped'];
            }

            $transport->start();
            $transport->stop();

            return ['healthy' => true, 'checked_via' => 'smtp'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'smtp'];
        }
    }

    /**
     * Webhook 探测：POST 最小健康载荷到机器端点（非用户可见）。
     */
    private function probeWebhook(): array
    {
        $url = (string) config('ops.alerts.webhook.url', '');
        $secret = (string) config('ops.alerts.webhook.secret', '');
        $body = json_encode(['event' => 'ops_health_check', 'time' => now()->toDateTimeString()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $request = Http::timeout(5)->withBody($body, 'application/json');

            if ($secret !== '') {
                $request = $request->withHeaders(['X-Ops-Signature' => hash_hmac('sha256', $body, $secret)]);
            }

            $response = $request->post($url);

            return $response->successful()
                ? ['healthy' => true, 'checked_via' => 'http_post']
                : ['healthy' => false, 'reason' => 'webhook_status_'.$response->status(), 'checked_via' => 'http_post'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'http_post'];
        }
    }

    /**
     * 钉钉探测：无静默 ping，发一条明确标注的轻量心跳，errcode==0 视为连通。
     */
    private function probeDingtalk(): array
    {
        $webhook = (string) config('ops.alerts.dingtalk.webhook', '');
        $secret = (string) config('ops.alerts.dingtalk.secret', '');
        $url = $webhook;

        if ($secret !== '') {
            $timestamp = (string) (int) round(microtime(true) * 1000);
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            $url .= (str_contains($url, '?') ? '&' : '?').'timestamp='.$timestamp.'&sign='.rawurlencode($sign);
        }

        try {
            $response = Http::timeout(5)->post($url, [
                'msgtype' => 'text',
                'text' => ['content' => $this->heartbeatText()],
            ]);

            $errcode = (int) data_get($response->json(), 'errcode', $response->successful() ? 0 : -1);

            return ($response->successful() && $errcode === 0)
                ? ['healthy' => true, 'checked_via' => 'heartbeat']
                : ['healthy' => false, 'reason' => 'dingtalk_errcode_'.$errcode, 'checked_via' => 'heartbeat'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'heartbeat'];
        }
    }

    /**
     * 飞书探测：无静默 ping，发一条明确标注的轻量心跳，code==0 视为连通。
     */
    private function probeFeishu(): array
    {
        $webhook = (string) config('ops.alerts.feishu.webhook', '');
        $secret = (string) config('ops.alerts.feishu.secret', '');
        $payload = [
            'msg_type' => 'text',
            'content' => ['text' => $this->heartbeatText()],
        ];

        if ($secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp."\n".$secret, true));
        }

        try {
            $response = Http::timeout(5)->post($webhook, $payload);
            $code = (int) data_get($response->json(), 'code', $response->successful() ? 0 : -1);

            return ($response->successful() && $code === 0)
                ? ['healthy' => true, 'checked_via' => 'heartbeat']
                : ['healthy' => false, 'reason' => 'feishu_code_'.$code, 'checked_via' => 'heartbeat'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'reason' => $this->safeExceptionMessage($e), 'checked_via' => 'heartbeat'];
        }
    }

    private function heartbeatText(): string
    {
        return 'Ops Center 告警通道健康自检：连通正常，请忽略。';
    }

    private function failure(string $channel, OpsAlert $alert, Throwable $e): array
    {
        Log::warning("Ops alert {$channel} notification failed", [
            'alert_id' => $alert->id,
            'message' => $this->safeExceptionMessage($e),
        ]);

        return ['enabled' => true, 'sent' => false, 'reason' => "{$channel}_exception"];
    }

    /**
     * Webhook 结构化载荷（不含凭据）。
     */
    private function webhookPayload(OpsAlert $alert): array
    {
        return [
            'title' => $alert->title,
            'severity' => $alert->severity,
            'source' => $alert->source,
            'status' => $alert->status,
            'message' => $alert->message,
            'time' => optional($alert->last_seen_at)->toDateTimeString(),
            'fingerprint' => $alert->fingerprint,
        ];
    }

    /**
     * 通知文本格式。
     */
    private function formatMessage(OpsAlert $alert): string
    {
        $template = '';

        try {
            $template = trim((string) OpsAlertSetting::value('message_template'));
        } catch (Throwable) {
            $template = '';
        }

        if ($template !== '') {
            return strtr($template, [
                '{title}' => (string) $alert->title,
                '{severity}' => (string) $alert->severity,
                '{source}' => (string) $alert->source,
                '{status}' => (string) $alert->status,
                '{time}' => optional($alert->last_seen_at)->toDateTimeString() ?? '',
                '{message}' => (string) $alert->message,
            ]);
        }

        return implode(PHP_EOL, [
            "Ops Center 告警：{$alert->title}",
            "级别：{$alert->severity}",
            "来源：{$alert->source}",
            "状态：{$alert->status}",
            '时间：'.optional($alert->last_seen_at)->toDateTimeString(),
            "说明：{$alert->message}",
        ]);
    }

    private function channelAllowed(OpsAlert $alert, string $channel): bool
    {
        if (! (bool) OpsAlertSetting::value("{$channel}_enabled")) {
            return false;
        }

        $matrix = (array) OpsAlertSetting::value('severity_channels');
        $severity = $alert->severity ?: 'warning';

        return (bool) data_get($matrix, "{$severity}.{$channel}", true);
    }

    private function credentialMissing(string $channel, string $key): bool
    {
        $value = config("ops.alerts.{$channel}.{$key}");

        if (is_array($value)) {
            return array_values(array_filter($value)) === [];
        }

        return (string) $value === '';
    }

    private function settingsAvailable(): bool
    {
        try {
            return Schema::hasTable('ops_alert_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function safeExceptionMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/https:\/\/api\.telegram\.org\/bot[^\/\s]+/i', 'https://api.telegram.org/bot[FILTERED]', $message) ?? $message;
        $message = preg_replace('/(token|password|secret|api[_-]?key|auth_signature|chat_id|access_token|sign)=([^&\s"]+)/i', '$1=[FILTERED]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [FILTERED]', $message) ?? $message;

        return mb_strimwidth($message, 0, 500, '...');
    }
}
