<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use App\Models\OpsAlertSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 告警通知服务。
 *
 * Telegram 与邮件均通过配置开关控制。未配置凭据时只记录告警，不阻塞采集流程。
 */
class AlertNotificationService
{
    /**
     * 获取通知通道配置状态。
     *
     * 只返回是否启用和是否配置完整，不返回 token、chat_id、邮箱等敏感值。
     */
    public function status(): array
    {
        $telegramEnabled = (bool) config('ops.alerts.telegram.enabled', false);
        $telegramToken = (string) config('ops.alerts.telegram.bot_token', '');
        $telegramChatId = (string) config('ops.alerts.telegram.chat_id', '');
        $mailEnabled = (bool) config('ops.alerts.mail.enabled', false);
        $mailTo = array_values(array_filter((array) config('ops.alerts.mail.to', [])));

        return [
            'telegram' => [
                'enabled' => $telegramEnabled && (bool) OpsAlertSetting::value('telegram_enabled'),
                'configured' => $telegramEnabled && $telegramToken !== '' && $telegramChatId !== '',
                'missing' => array_values(array_filter([
                    $telegramEnabled ? null : 'enabled',
                    $telegramToken === '' ? 'bot_token' : null,
                    $telegramChatId === '' ? 'chat_id' : null,
                ])),
            ],
            'mail' => [
                'enabled' => $mailEnabled && (bool) OpsAlertSetting::value('mail_enabled'),
                'configured' => $mailEnabled && $mailTo !== [],
                'missing' => array_values(array_filter([
                    $mailEnabled ? null : 'enabled',
                    $mailTo === [] ? 'to' : null,
                ])),
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 发送告警通知。
     */
    public function send(OpsAlert $alert): array
    {
        return [
            'telegram' => $this->sendTelegram($alert),
            'mail' => $this->sendMail($alert),
        ];
    }

    /**
     * 发送测试通知。
     *
     * 测试通知不会写入告警表，专门用于验证 Telegram / 邮件配置是否可用。
     */
    public function sendTest(array $channels = [], ?string $message = null): array
    {
        $channels = $channels ?: ['telegram', 'mail'];
        $channels = array_values(array_intersect($channels, ['telegram', 'mail']));
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

        if (in_array('telegram', $channels, true)) {
            $result['telegram'] = $this->sendTelegram($alert);
        }

        if (in_array('mail', $channels, true)) {
            $result['mail'] = $this->sendMail($alert);
        }

        return $result;
    }

    /**
     * Telegram 告警。
     */
    private function sendTelegram(OpsAlert $alert): array
    {
        if (! $this->channelAllowed($alert, 'telegram')) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'channel_disabled_by_policy'];
        }

        if (! (bool) config('ops.alerts.telegram.enabled', false)) {
            return ['enabled' => false, 'sent' => false];
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

            return [
                'enabled' => true,
                'sent' => $response->successful(),
                'status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Ops alert telegram notification failed', [
                'alert_id' => $alert->id,
                'message' => $e->getMessage(),
            ]);

            return ['enabled' => true, 'sent' => false, 'reason' => 'telegram_exception'];
        }
    }

    /**
     * 邮件告警。
     */
    private function sendMail(OpsAlert $alert): array
    {
        if (! $this->channelAllowed($alert, 'mail')) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'channel_disabled_by_policy'];
        }

        if (! (bool) config('ops.alerts.mail.enabled', false)) {
            return ['enabled' => false, 'sent' => false];
        }

        $to = (array) config('ops.alerts.mail.to', []);
        $to = array_values(array_filter($to));

        if ($to === []) {
            return ['enabled' => true, 'sent' => false, 'reason' => 'mail_recipient_empty'];
        }

        try {
            Mail::raw($this->formatMessage($alert), function ($message) use ($alert, $to): void {
                $message->to($to)->subject("[Ops Center][{$alert->severity}] {$alert->title}");
            });

            return ['enabled' => true, 'sent' => true];
        } catch (\Throwable $e) {
            Log::warning('Ops alert mail notification failed', [
                'alert_id' => $alert->id,
                'message' => $e->getMessage(),
            ]);

            return ['enabled' => true, 'sent' => false, 'reason' => 'mail_exception'];
        }
    }

    /**
     * 通知文本格式。
     */
    private function formatMessage(OpsAlert $alert): string
    {
        return implode(PHP_EOL, [
            "Ops Center 告警：{$alert->title}",
            "级别：{$alert->severity}",
            "来源：{$alert->source}",
            "状态：{$alert->status}",
            "时间：".optional($alert->last_seen_at)->toDateTimeString(),
            "说明：{$alert->message}",
        ]);
    }

    private function channelAllowed(OpsAlert $alert, string $channel): bool
    {
        if ($channel === 'telegram' && ! (bool) OpsAlertSetting::value('telegram_enabled')) {
            return false;
        }

        if ($channel === 'mail' && ! (bool) OpsAlertSetting::value('mail_enabled')) {
            return false;
        }

        $matrix = (array) OpsAlertSetting::value('severity_channels');
        $severity = $alert->severity ?: 'warning';

        return (bool) data_get($matrix, "{$severity}.{$channel}", true);
    }
}
