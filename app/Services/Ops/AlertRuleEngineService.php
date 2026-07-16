<?php

namespace App\Services\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsAlertRule;

/**
 * Ops Center 告警规则引擎。
 *
 * 只处理轻量摘要数据，避免在规则判断中读取或传播大日志正文。
 */
class AlertRuleEngineService
{
    /**
     * 根据系统快照生成告警 DTO。
     */
    public function detect(array $snapshot): array
    {
        return array_values(array_filter([
            ...$this->diskAlerts((array) ($snapshot['disk'] ?? [])),
            ...$this->queueAlerts((array) ($snapshot['queue'] ?? [])),
            ...$this->dockerAlerts((array) ($snapshot['docker'] ?? [])),
            ...$this->networkAlerts((array) ($snapshot['network'] ?? [])),
        ]));
    }

    /**
     * 磁盘阈值告警。
     */
    private function diskAlerts(array $disk): array
    {
        $rule = $this->rule('disk_usage', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.disk_usage_warning', 85),
            'critical_threshold' => (float) config('ops.alerts.thresholds.disk_usage_critical', 95),
        ]);

        if ($rule === null) {
            return [];
        }

        $threshold = (float) $rule['warning_threshold'];
        $criticalThreshold = (float) ($rule['critical_threshold'] ?? $threshold);
        $alerts = [];

        foreach ((array) ($disk['disks'] ?? []) as $item) {
            $usage = (float) ($item['usage'] ?? 0);

            if ($usage < $threshold) {
                continue;
            }

            $severity = $usage >= $criticalThreshold ? 'critical' : 'warning';
            $mount = (string) ($item['mount'] ?? '-');

            $alerts[] = new AlertDTO(
                source: 'disk',
                severity: $severity,
                title: "磁盘使用率过高：{$mount}",
                message: "挂载点 {$mount} 当前使用率 {$usage}%，已超过 {$threshold}% 告警阈值。",
                context: [
                    'target' => $mount,
                    'mount' => $mount,
                    'usage' => $usage,
                    'filesystem' => $item['filesystem'] ?? null,
                ],
            );
        }

        return $alerts;
    }

    /**
     * 队列堆积与失败任务告警。
     */
    private function queueAlerts(array $queue): array
    {
        $pendingRule = $this->rule('queue_pending', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.queue_pending_warning', 100),
        ]);
        $failedRule = $this->rule('failed_jobs', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.failed_jobs_warning', 1),
        ]);
        $alerts = [];

        if ($pendingRule !== null) {
            $pendingThreshold = (float) $pendingRule['warning_threshold'];

            foreach ((array) ($queue['queues'] ?? []) as $item) {
                $pending = (float) ($item['pending'] ?? 0);

                if ($pending >= $pendingThreshold) {
                    $queueName = (string) ($item['name'] ?? 'default');
                    $alerts[] = new AlertDTO(
                        source: 'queue',
                        severity: 'warning',
                        title: "队列堆积：{$queueName}",
                        message: "队列 {$queueName} 当前 pending {$pending}，已超过 {$pendingThreshold} 阈值。",
                        context: [
                            'target' => $queueName,
                            'queue' => $queueName,
                            'pending' => $pending,
                        ],
                    );
                }
            }
        }

        $failed = (float) data_get($queue, 'failed_jobs.count', 0);

        if ($failedRule !== null && $failed >= (float) $failedRule['warning_threshold']) {
            $alerts[] = new AlertDTO(
                source: 'queue',
                severity: 'warning',
                title: '存在失败队列任务',
                message: "failed_jobs 当前共有 {$failed} 条失败记录，请及时处理。",
                context: [
                    'target' => 'failed_jobs',
                    'failed_jobs' => $failed,
                ],
            );
        }

        return $alerts;
    }

    /**
     * Docker 异常容器告警。
     */
    private function dockerAlerts(array $docker): array
    {
        $unhealthyRule = $this->rule('docker_unhealthy', [
            'warning_threshold' => 1,
            'critical_threshold' => 1,
        ]);
        $exitedRule = (bool) config('ops.alerts.thresholds.docker_exited_enabled', true)
            ? $this->rule('docker_exited', ['warning_threshold' => 1])
            : null;
        $unhealthy = (float) ($docker['unhealthy'] ?? 0);
        $exited = (float) ($docker['exited'] ?? 0);
        $alerts = [];

        if ($unhealthyRule !== null && $unhealthy >= (float) $unhealthyRule['warning_threshold']) {
            $alerts[] = new AlertDTO(
                source: 'docker',
                severity: 'critical',
                title: 'Docker 存在 unhealthy 容器',
                message: "当前检测到 {$unhealthy} 个 unhealthy 容器。",
                context: [
                    'target' => 'unhealthy',
                    'unhealthy' => $unhealthy,
                ],
            );
        }

        if ($exitedRule !== null && $exited >= (float) $exitedRule['warning_threshold']) {
            $alerts[] = new AlertDTO(
                source: 'docker',
                severity: 'warning',
                title: 'Docker 存在已退出容器',
                message: "当前检测到 {$exited} 个 exited 容器。",
                context: [
                    'target' => 'exited',
                    'exited' => $exited,
                ],
            );
        }

        return $alerts;
    }

    /**
     * 网络吞吐告警。
     */
    private function networkAlerts(array $network): array
    {
        $rule = $this->rule('network_mbps', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.network_mbps_warning', 50),
        ]);

        if ($rule === null) {
            return [];
        }

        $threshold = (float) $rule['warning_threshold'];
        $rx = (float) data_get($network, 'summary.rx_mb_s', 0);
        $tx = (float) data_get($network, 'summary.tx_mb_s', 0);
        $peak = max($rx, $tx);

        if ($peak < $threshold) {
            return [];
        }

        return [
            new AlertDTO(
                source: 'network',
                severity: 'warning',
                title: '网络吞吐过高',
                message: "当前网络峰值 {$peak} MB/s，已超过 {$threshold} MB/s 阈值。",
                context: [
                    'target' => 'network',
                    'rx_mb_s' => $rx,
                    'tx_mb_s' => $tx,
                ],
            ),
        ];
    }

    /**
     * 获取规则阈值。
     *
     * 规则不存在时使用历史 config 默认值；规则存在但被禁用时返回 null。
     */
    private function rule(string $key, array $fallback): ?array
    {
        $rule = OpsAlertRule::query()->where('key', $key)->first();

        if ($rule === null) {
            return $fallback;
        }

        if (! $rule->is_active) {
            return null;
        }

        return [
            'warning_threshold' => $rule->warning_threshold,
            'critical_threshold' => $rule->critical_threshold,
        ];
    }
}
