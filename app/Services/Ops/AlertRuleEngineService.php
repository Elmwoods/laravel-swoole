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
    public function __construct(private readonly AlertRuleRegistryService $registry) {}

    /**
     * 根据系统快照生成告警 DTO。
     */
    public function detect(array $snapshot): array
    {
        $this->registry->syncDefaults();

        return array_values(array_filter([
            ...$this->diskAlerts((array) ($snapshot['disk'] ?? [])),
            ...$this->queueAlerts((array) ($snapshot['queue'] ?? [])),
            ...$this->dockerAlerts((array) ($snapshot['docker'] ?? [])),
            ...$this->networkAlerts((array) ($snapshot['network'] ?? [])),
            ...$this->systemAlerts((array) ($snapshot['system'] ?? [])),
            ...$this->redisAlerts((array) ($snapshot['redis'] ?? [])),
            ...$this->mysqlAlerts((array) ($snapshot['mysql'] ?? [])),
            ...$this->octaneAlerts((array) ($snapshot['octane'] ?? [])),
            ...$this->supervisorAlerts((array) ($snapshot['supervisor'] ?? [])),
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

    private function systemAlerts(array $system): array
    {
        return [
            ...$this->percentageAlert('system_cpu', 'system', 'CPU 使用率过高', (float) ($system['cpu_percent'] ?? 0), 'cpu'),
            ...$this->percentageAlert('system_memory', 'system', '内存使用率过高', (float) data_get($system, 'memory.percent', 0), 'memory'),
        ];
    }

    private function redisAlerts(array $redis): array
    {
        $rule = $this->rule('redis_connected', ['warning_threshold' => 1]);

        if ($rule === null || ! array_key_exists('connected', $redis) || (bool) $redis['connected']) {
            return [];
        }

        return [
            new AlertDTO(
                source: 'redis',
                severity: 'critical',
                title: 'Redis 连接异常',
                message: 'Redis 当前不可连接，请检查 Redis 服务或网络。',
                context: ['target' => 'redis'],
            ),
        ];
    }

    private function mysqlAlerts(array $mysql): array
    {
        $rule = $this->rule('mysql_connected', ['warning_threshold' => 1]);

        if ($rule === null || ! array_key_exists('connected', $mysql) || (bool) $mysql['connected']) {
            return [];
        }

        return [
            new AlertDTO(
                source: 'mysql',
                severity: 'critical',
                title: 'MySQL 连接异常',
                message: 'MySQL 当前不可连接，请检查数据库服务或网络。',
                context: ['target' => 'mysql'],
            ),
        ];
    }

    private function octaneAlerts(array $octane): array
    {
        $rule = $this->rule('octane_running', ['warning_threshold' => 1]);

        if ($rule === null || $octane === []) {
            return [];
        }

        $running = (bool) ($octane['running'] ?? false);
        $processCount = (float) ($octane['process_count'] ?? 0);

        if ($running && $processCount >= (float) $rule['warning_threshold']) {
            return [];
        }

        return [
            new AlertDTO(
                source: 'octane',
                severity: 'critical',
                title: 'Octane 运行异常',
                message: "Octane 当前运行状态异常，worker 数量 {$processCount}。",
                context: [
                    'target' => 'octane',
                    'running' => $running,
                    'process_count' => $processCount,
                ],
            ),
        ];
    }

    private function supervisorAlerts(array $processes): array
    {
        $rule = $this->rule('supervisor_process_down', ['warning_threshold' => 1]);

        if ($rule === null) {
            return [];
        }

        if (array_key_exists('services', $processes) && is_array($processes['services'])) {
            $processes = $processes['services'];
        }

        return collect($processes)
            ->filter(function (array $process): bool {
                $name = trim((string) ($process['name'] ?? ''));
                $state = strtoupper(trim((string) ($process['status'] ?? $process['state'] ?? '')));

                return $name !== '' && $state !== '' && $state !== 'RUNNING';
            })
            ->map(function (array $process): AlertDTO {
                $name = trim((string) ($process['name'] ?? ''));
                $state = strtoupper(trim((string) ($process['status'] ?? $process['state'] ?? 'unknown')));

                return new AlertDTO(
                    source: 'supervisor',
                    severity: 'warning',
                    title: 'Supervisor 进程异常：'.$name,
                    message: 'Supervisor 进程 '.$name.' 当前状态为 '.$state.'。',
                    context: [
                        'target' => $name,
                        'state' => $state,
                        'description' => $process['description'] ?? null,
                    ],
                );
            })
            ->values()
            ->all();
    }

    private function percentageAlert(string $ruleKey, string $source, string $title, float $value, string $target): array
    {
        $rule = $this->rule($ruleKey, ['warning_threshold' => 85, 'critical_threshold' => 95]);

        if ($rule === null || $value < (float) $rule['warning_threshold']) {
            return [];
        }

        $criticalThreshold = (float) ($rule['critical_threshold'] ?? $rule['warning_threshold']);
        $severity = $value >= $criticalThreshold ? 'critical' : 'warning';

        return [
            new AlertDTO(
                source: $source,
                severity: $severity,
                title: $title,
                message: "{$title}，当前值 {$value}%，已超过 ".(float) $rule['warning_threshold'].'% 阈值。',
                context: [
                    'target' => $target,
                    'value' => $value,
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
