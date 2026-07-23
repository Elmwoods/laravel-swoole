<?php

namespace App\Services\Ops;

use App\Models\OpsChannelHealth;

/**
 * 通知通道健康自检：定时静默探测每个已启用通道的连通性，记录每通道健康态，
 * 连续失败超阈值升 channel_health 告警，恢复即自动 resolve。
 */
class AlertChannelHealthService
{
    private const LABELS = [
        'telegram' => 'Telegram',
        'mail' => '邮件',
        'webhook' => 'Webhook',
        'dingtalk' => '钉钉',
        'feishu' => '飞书',
    ];

    public function __construct(
        private readonly AlertNotificationService $notification,
        private readonly AlertCenterService $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $dryRun = false): array
    {
        if (! (bool) config('ops.alerts.health.enabled', false)) {
            return ['enabled' => false, 'probed' => 0, 'healthy' => 0, 'failing' => 0, 'raised' => 0, 'resolved' => 0];
        }

        $threshold = max(1, (int) config('ops.alerts.health.fail_threshold', 2));

        $channels = $this->notification->channels();
        $only = (array) config('ops.alerts.health.probe_channels', []);
        if ($only !== []) {
            $channels = array_values(array_intersect($channels, $only));
        }

        $probed = 0;
        $healthy = 0;
        $failing = 0;
        $raised = 0;
        $resolved = 0;

        foreach ($channels as $channel) {
            $result = $this->notification->probeChannel($channel);

            // 未启用 / 未配置的通道不计入健康统计（status() 已标「未就绪」）。
            if (($result['checked_via'] ?? 'skipped') === 'skipped') {
                continue;
            }

            $probed++;

            if ($dryRun) {
                $result['healthy'] ? $healthy++ : $failing++;

                continue;
            }

            if ($result['healthy']) {
                $healthy++;

                if ($this->applyHealthy($channel)) {
                    $this->alerts->resolveChannelHealthAlert($channel);
                    $resolved++;
                }

                continue;
            }

            $failing++;
            $reason = (string) ($result['reason'] ?? 'unknown');
            $consecutive = $this->applyFailure($channel, $reason);

            if ($consecutive >= $threshold) {
                $this->alerts->raiseChannelHealthAlert(
                    $channel,
                    $this->label($channel)."通道连通性自检连续失败 {$consecutive} 次：{$reason}",
                    [
                        'channel' => $channel,
                        'consecutive_failures' => $consecutive,
                        'checked_via' => $result['checked_via'] ?? 'unknown',
                    ],
                );
                $raised++;
            }
        }

        return [
            'enabled' => true,
            'probed' => $probed,
            'healthy' => $healthy,
            'failing' => $failing,
            'raised' => $raised,
            'resolved' => $resolved,
        ];
    }

    /**
     * 记录健康，返回该通道此前是否处于 failing（用于触发告警 resolve）。
     */
    private function applyHealthy(string $channel): bool
    {
        $row = OpsChannelHealth::query()->firstOrNew(['channel' => $channel]);
        $wasFailing = $row->exists && $row->status === 'failing';

        $row->fill([
            'status' => 'healthy',
            'consecutive_failures' => 0,
            'last_ok_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
        ])->save();

        return $wasFailing;
    }

    /**
     * 记录失败，返回累计连续失败次数。
     */
    private function applyFailure(string $channel, string $reason): int
    {
        $row = OpsChannelHealth::query()->firstOrNew(['channel' => $channel]);
        $consecutive = ($row->exists ? (int) $row->consecutive_failures : 0) + 1;

        $row->fill([
            'status' => 'failing',
            'consecutive_failures' => $consecutive,
            'last_checked_at' => now(),
            'last_error' => mb_strimwidth($reason, 0, 300, '...'),
        ])->save();

        return $consecutive;
    }

    private function label(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }
}
