<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
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
     * Telegram 告警。
     */
    private function sendTelegram(OpsAlert $alert): array
    {
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
}
