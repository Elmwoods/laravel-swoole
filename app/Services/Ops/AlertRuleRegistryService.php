<?php

namespace App\Services\Ops;

use App\Models\OpsAlertRule;
use Illuminate\Support\Collection;

class AlertRuleRegistryService
{
    public const DEFINITIONS = [
        'disk_usage' => [
            'name' => '磁盘使用率',
            'source' => 'disk',
            'metric' => 'usage_percent',
            'operator' => '>=',
            'warning_threshold' => 85,
            'critical_threshold' => 95,
            'unit' => '%',
            'description' => '磁盘挂载点使用率达到阈值时触发告警。',
            'sort_order' => 10,
            'min' => 0,
            'max' => 100,
            'requires_critical' => true,
        ],
        'queue_pending' => [
            'name' => '队列堆积',
            'source' => 'queue',
            'metric' => 'pending_jobs',
            'operator' => '>=',
            'warning_threshold' => 100,
            'critical_threshold' => null,
            'unit' => 'jobs',
            'description' => '队列待处理任务数量达到阈值时触发告警。',
            'sort_order' => 20,
            'min' => 0,
            'max' => 1000000,
            'requires_critical' => false,
        ],
        'failed_jobs' => [
            'name' => '失败任务',
            'source' => 'queue',
            'metric' => 'failed_jobs',
            'operator' => '>=',
            'warning_threshold' => 1,
            'critical_threshold' => null,
            'unit' => 'jobs',
            'description' => '失败队列任务数量达到阈值时触发告警。',
            'sort_order' => 30,
            'min' => 0,
            'max' => 1000000,
            'requires_critical' => false,
        ],
        'docker_unhealthy' => [
            'name' => 'Docker unhealthy 容器',
            'source' => 'docker',
            'metric' => 'unhealthy_count',
            'operator' => '>=',
            'warning_threshold' => 1,
            'critical_threshold' => 1,
            'unit' => 'containers',
            'description' => '存在 unhealthy 容器时触发严重告警。',
            'sort_order' => 40,
            'min' => 0,
            'max' => 1000000,
            'requires_critical' => false,
        ],
        'docker_exited' => [
            'name' => 'Docker exited 容器',
            'source' => 'docker',
            'metric' => 'exited_count',
            'operator' => '>=',
            'warning_threshold' => 1,
            'critical_threshold' => null,
            'unit' => 'containers',
            'description' => '存在已退出容器时触发预警。',
            'sort_order' => 50,
            'min' => 0,
            'max' => 1000000,
            'requires_critical' => false,
        ],
        'network_mbps' => [
            'name' => '网络吞吐',
            'source' => 'network',
            'metric' => 'mbps',
            'operator' => '>=',
            'warning_threshold' => 50,
            'critical_threshold' => null,
            'unit' => 'MB/s',
            'description' => '网络收发峰值达到阈值时触发告警。',
            'sort_order' => 60,
            'min' => 0,
            'max' => 100000,
            'requires_critical' => false,
        ],
        'system_cpu' => [
            'name' => 'CPU 使用率',
            'source' => 'system',
            'metric' => 'cpu_percent',
            'operator' => '>=',
            'warning_threshold' => 85,
            'critical_threshold' => 95,
            'unit' => '%',
            'description' => '系统 CPU 使用率达到阈值时触发告警。',
            'sort_order' => 70,
            'min' => 0,
            'max' => 100,
            'requires_critical' => true,
        ],
        'system_memory' => [
            'name' => '内存使用率',
            'source' => 'system',
            'metric' => 'memory_percent',
            'operator' => '>=',
            'warning_threshold' => 85,
            'critical_threshold' => 95,
            'unit' => '%',
            'description' => '系统内存使用率达到阈值时触发告警。',
            'sort_order' => 80,
            'min' => 0,
            'max' => 100,
            'requires_critical' => true,
        ],
        'redis_connected' => [
            'name' => 'Redis 连接状态',
            'source' => 'redis',
            'metric' => 'connected',
            'operator' => '=',
            'warning_threshold' => 1,
            'critical_threshold' => null,
            'unit' => 'boolean',
            'description' => 'Redis 不可连接时触发告警。',
            'sort_order' => 90,
            'min' => 0,
            'max' => 1,
            'requires_critical' => false,
        ],
        'mysql_connected' => [
            'name' => 'MySQL 连接状态',
            'source' => 'mysql',
            'metric' => 'connected',
            'operator' => '=',
            'warning_threshold' => 1,
            'critical_threshold' => null,
            'unit' => 'boolean',
            'description' => 'MySQL 不可连接时触发告警。',
            'sort_order' => 100,
            'min' => 0,
            'max' => 1,
            'requires_critical' => false,
        ],
        'octane_running' => [
            'name' => 'Octane 运行状态',
            'source' => 'octane',
            'metric' => 'running',
            'operator' => '=',
            'warning_threshold' => 1,
            'critical_threshold' => null,
            'unit' => 'boolean',
            'description' => 'Octane 未运行或 worker 数量异常时触发告警。',
            'sort_order' => 110,
            'min' => 0,
            'max' => 1000000,
            'requires_critical' => false,
        ],
        'supervisor_process_down' => [
            'name' => 'Supervisor 进程异常',
            'source' => 'supervisor',
            'metric' => 'process_state',
            'operator' => '!=',
            'warning_threshold' => 1,
            'critical_threshold' => null,
            'unit' => 'processes',
            'description' => 'Supervisor 管理的进程不为 RUNNING 时触发告警。',
            'sort_order' => 120,
            'min' => 0,
            'max' => 1000000,
            'requires_critical' => false,
        ],
    ];

    public function syncDefaults(): Collection
    {
        return collect(self::DEFINITIONS)
            ->map(function (array $definition, string $key): OpsAlertRule {
                $rule = OpsAlertRule::query()->firstOrNew(['key' => $key]);

                $rule->fill([
                    'name' => $definition['name'],
                    'source' => $definition['source'],
                    'metric' => $definition['metric'],
                    'operator' => $definition['operator'],
                    'unit' => $definition['unit'],
                    'description' => $definition['description'],
                    'sort_order' => $definition['sort_order'],
                ]);

                if (! $rule->exists) {
                    $thresholds = $this->initialThresholds($key, $definition);
                    $rule->warning_threshold = $thresholds['warning_threshold'];
                    $rule->critical_threshold = $thresholds['critical_threshold'];
                    $rule->is_active = true;
                }

                $rule->save();

                return $rule;
            })
            ->values();
    }

    public function definition(string $key): ?array
    {
        return self::DEFINITIONS[$key] ?? null;
    }

    public function allowedKeys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function find(string $key): ?OpsAlertRule
    {
        $this->syncDefaults();

        if (! isset(self::DEFINITIONS[$key])) {
            return null;
        }

        return OpsAlertRule::query()->where('key', $key)->first();
    }

    public function all(): Collection
    {
        $this->syncDefaults();

        return OpsAlertRule::query()
            ->whereIn('key', $this->allowedKeys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function initialThresholds(string $key, array $definition): array
    {
        return match ($key) {
            'disk_usage' => [
                'warning_threshold' => (float) config('ops.alerts.thresholds.disk_usage_warning', $definition['warning_threshold']),
                'critical_threshold' => (float) config('ops.alerts.thresholds.disk_usage_critical', $definition['critical_threshold']),
            ],
            'queue_pending' => [
                'warning_threshold' => (float) config('ops.alerts.thresholds.queue_pending_warning', $definition['warning_threshold']),
                'critical_threshold' => null,
            ],
            'failed_jobs' => [
                'warning_threshold' => (float) config('ops.alerts.thresholds.failed_jobs_warning', $definition['warning_threshold']),
                'critical_threshold' => null,
            ],
            'network_mbps' => [
                'warning_threshold' => (float) config('ops.alerts.thresholds.network_mbps_warning', $definition['warning_threshold']),
                'critical_threshold' => null,
            ],
            'system_cpu' => [
                'warning_threshold' => (float) config('ops.alerts.thresholds.system_cpu_warning', $definition['warning_threshold']),
                'critical_threshold' => (float) config('ops.alerts.thresholds.system_cpu_critical', $definition['critical_threshold']),
            ],
            'system_memory' => [
                'warning_threshold' => (float) config('ops.alerts.thresholds.system_memory_warning', $definition['warning_threshold']),
                'critical_threshold' => (float) config('ops.alerts.thresholds.system_memory_critical', $definition['critical_threshold']),
            ],
            default => [
                'warning_threshold' => $definition['warning_threshold'],
                'critical_threshold' => $definition['critical_threshold'],
            ],
        };
    }
}
