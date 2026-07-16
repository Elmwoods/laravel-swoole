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
                    $rule->warning_threshold = $definition['warning_threshold'];
                    $rule->critical_threshold = $definition['critical_threshold'];
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
}
