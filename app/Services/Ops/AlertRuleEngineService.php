<?php

namespace App\Services\Ops;

use App\DTO\Ops\AlertDTO;

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
        $threshold = (int) config('ops.alerts.thresholds.disk_usage_warning', 85);
        $criticalThreshold = (int) config('ops.alerts.thresholds.disk_usage_critical', 95);
        $alerts = [];

        foreach ((array) ($disk['disks'] ?? []) as $item) {
            $usage = (int) ($item['usage'] ?? 0);

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
        $pendingThreshold = (int) config('ops.alerts.thresholds.queue_pending_warning', 100);
        $failedThreshold = (int) config('ops.alerts.thresholds.failed_jobs_warning', 1);
        $alerts = [];

        foreach ((array) ($queue['queues'] ?? []) as $item) {
            $pending = (int) ($item['pending'] ?? 0);

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

        $failed = (int) data_get($queue, 'failed_jobs.count', 0);

        if ($failed >= $failedThreshold) {
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
        $unhealthy = (int) ($docker['unhealthy'] ?? 0);
        $exited = (int) ($docker['exited'] ?? 0);
        $alerts = [];

        if ($unhealthy > 0) {
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

        if ($exited > 0 && (bool) config('ops.alerts.thresholds.docker_exited_enabled', true)) {
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
        $threshold = (float) config('ops.alerts.thresholds.network_mbps_warning', 50);
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
}
