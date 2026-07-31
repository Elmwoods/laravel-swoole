<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlertRule;
use Illuminate\Support\Collection;

/**
 * 告警规则登记表：告警子系统所有可触发规则的「单一事实来源」（阈值、来源、指标、算子等元数据）。
 *
 * 在 raise/notify/broadcast 管线中，本类站在最上游的 raise 前置：采集器读取本表里各规则的
 * warning/critical 阈值，据此判定某来源指标是否越线、以何种级别 raise 出告警。本类负责：
 *  - 把内置 DEFINITIONS 同步进 DB（syncDefaults），保证规则行始终存在；
 *  - 供查询单条/全部规则（find/all/definition/allowedKeys）；
 *  - 导出/导入用户可调字段（export/import），实现跨环境迁移与版本化备份；
 *  - 每次导入改动阈值/启停时，委托 AlertRuleChangeService 落变更历史（审计）。
 * 元数据（name/source/metric 等）以代码内 DEFINITIONS 为准，用户只能改阈值与启停两类字段。
 */
class AlertRuleRegistryService
{
    // 注入变更历史服务：import 改动阈值/启停时用它记录字段级 diff。
    public function __construct(private readonly AlertRuleChangeService $changes) {}

    // 内置规则定义：key => 元数据。requires_critical 标识该规则是否必须配 critical 阈值；
    // min/max 为导入时的阈值合法区间；warning/critical_threshold 为出厂默认值。
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

    /**
     * 作用：把内置 DEFINITIONS 同步进 DB——已存在的行只刷新元数据，新行则补齐初始阈值并默认启用。
     *
     * 为什么只有新行才写阈值/启停：阈值与 is_active 是用户可调字段，已存在的规则不能被同步覆盖掉用户改动；
     * 而元数据（name/source/metric 等）以代码为准，每次都 fill 刷新，保证描述随版本更新。
     *
     * @return Collection<int, OpsAlertRule> 同步后的规则集合
     */
    public function syncDefaults(): Collection
    {
        return collect(self::DEFINITIONS)
            ->map(function (array $definition, string $key): OpsAlertRule {
                $rule = OpsAlertRule::query()->firstOrNew(['key' => $key]); // 有则取、无则新建（尚未落库）

                // 元数据每次都以代码为准刷新（不含阈值/启停，避免覆盖用户改动）。
                $rule->fill([
                    'name' => $definition['name'],
                    'source' => $definition['source'],
                    'metric' => $definition['metric'],
                    'operator' => $definition['operator'],
                    'unit' => $definition['unit'],
                    'description' => $definition['description'],
                    'sort_order' => $definition['sort_order'],
                ]);

                // 仅对首次创建的规则写入初始阈值与默认启用；已存在的保留用户已有配置。
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

    /**
     * 作用：按 key 取内置元数据定义。
     *
     * @param  string  $key  规则 key
     * @return array<string, mixed>|null 该规则的 DEFINITIONS 定义；不存在则 null
     */
    public function definition(string $key): ?array
    {
        return self::DEFINITIONS[$key] ?? null;
    }

    /**
     * 作用：返回所有合法规则 key 的白名单（import/all 用于过滤非法 key）。
     *
     * @return array<int, string> 规则 key 列表
     */
    public function allowedKeys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * 作用：按 key 查单条规则模型（先确保默认已同步入库）。
     *
     * 为什么先 syncDefaults：规则可能从未被写过库，先同步保证「白名单里的 key 一定查得到行」。
     *
     * @param  string  $key  规则 key
     * @return OpsAlertRule|null 规则模型；key 不在白名单则 null
     */
    public function find(string $key): ?OpsAlertRule
    {
        $this->syncDefaults();

        // 双保险：即便 DB 里有历史脏 key，也只认当前白名单内的定义。
        if (! isset(self::DEFINITIONS[$key])) {
            return null;
        }

        return OpsAlertRule::query()->where('key', $key)->first();
    }

    /**
     * 作用：返回全部（白名单内）规则，按 sort_order、id 排序，供管理页/采集器遍历。
     *
     * @return Collection<int, OpsAlertRule> 规则集合
     */
    public function all(): Collection
    {
        $this->syncDefaults(); // 先同步，保证返回集合齐全

        return OpsAlertRule::query()
            ->whereIn('key', $this->allowedKeys()) // 只取白名单内的 key，过滤历史遗留脏行
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * 作用：导出全部规则的用户可调字段（阈值 + 启停）及导出时间，供跨环境迁移 / 版本化备份。
     *
     * 为什么只导可调字段：元数据以代码 DEFINITIONS 为准，导出它无意义；备份的价值在于用户改过的阈值与启停。
     *
     * @return array{exported_at:string, rules:array<int, array<string, mixed>>} 导出载荷
     */
    public function export(): array
    {
        return [
            'exported_at' => now()->toDateTimeString(),
            'rules' => $this->all()
                ->map(fn (OpsAlertRule $rule): array => [
                    'key' => $rule->key,
                    'name' => $rule->name, // 仅供人读，导入时不依赖它
                    'warning_threshold' => $rule->warning_threshold,
                    'critical_threshold' => $rule->critical_threshold,
                    'is_active' => (bool) $rule->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * 作用：导入规则的用户可调字段。仅认白名单 key、按每个 key 的 min/max 校验，
     * 非法项逐条跳过并报告（不整单失败）。
     *
     * 为什么逐条容错而非整单事务回滚：导入常来自跨环境/跨版本备份，可能含个别失效 key 或越界值，
     * 「跳过坏项、应用好项、回报 skipped」比整单失败更实用；每条成功应用都会落一条变更历史。
     *
     * @param  array<int, mixed>  $rules  导入项列表，每项形如 {key, warning_threshold, critical_threshold, is_active}
     * @param  AdminUser|null  $actor  操作人，透传给变更历史
     * @return array{applied:int, total:int, skipped:array<int, array{key:mixed, reason:string}>} 应用/总数/跳过明细
     */
    public function import(array $rules, ?AdminUser $actor = null): array
    {
        $this->syncDefaults(); // 先确保规则行都在，import 才有行可 update

        $applied = 0;
        $skipped = [];

        foreach ($rules as $incoming) {
            $key = is_array($incoming) ? ($incoming['key'] ?? null) : null;

            // key 非字符串或不在白名单 → 记 unknown_key 跳过，不让未知规则混入。
            if (! is_string($key) || ! isset(self::DEFINITIONS[$key])) {
                $skipped[] = ['key' => $key, 'reason' => 'unknown_key'];

                continue;
            }

            $definition = self::DEFINITIONS[$key];
            $min = $definition['min'] ?? 0;        // 该 key 允许的阈值下界
            $max = $definition['max'] ?? 1000000;  // 该 key 允许的阈值上界

            $warning = $incoming['warning_threshold'] ?? null;
            $criticalRaw = $incoming['critical_threshold'] ?? null;

            // warning 是必填数值，缺失/非数值直接判非法。
            if (! is_numeric($warning)) {
                $skipped[] = ['key' => $key, 'reason' => 'invalid'];

                continue;
            }

            $warning = (float) $warning;
            // critical 允许留空（null/空串 → null，表示该规则不设严重阈值）。
            $critical = $criticalRaw === null || $criticalRaw === '' ? null : (float) $criticalRaw;

            // warning 越界 → 非法跳过。
            if ($warning < $min || $warning > $max) {
                $skipped[] = ['key' => $key, 'reason' => 'invalid'];

                continue;
            }

            // critical 若给了值也必须在 [min,max] 内。
            if ($critical !== null && ($critical < $min || $critical > $max)) {
                $skipped[] = ['key' => $key, 'reason' => 'invalid'];

                continue;
            }

            // 单调性约束：critical 不得低于 warning，否则「更严重」的线反而更早触发，语义矛盾。
            if ($critical !== null && $critical < $warning) {
                $skipped[] = ['key' => $key, 'reason' => 'invalid'];

                continue;
            }

            $rule = OpsAlertRule::query()->where('key', $key)->first();

            // 理论上 syncDefaults 后应存在；防御性兜底：查不到行也记跳过而非报错。
            if ($rule === null) {
                $skipped[] = ['key' => $key, 'reason' => 'unknown_key'];

                continue;
            }

            // 先快照旧值，供变更历史做旧→新 diff。
            $old = ['warning_threshold' => $rule->warning_threshold, 'critical_threshold' => $rule->critical_threshold, 'is_active' => $rule->is_active];
            $new = [
                'warning_threshold' => $warning,
                'critical_threshold' => $critical,
                // is_active 缺省视为 true；用 FILTER_VALIDATE_BOOL 兼容 "true"/"1"/1 等多种表示。
                'is_active' => filter_var($incoming['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            ];
            $rule->forceFill($new)->save(); // forceFill 绕过 fillable 限制，直写这三个可调字段
            $this->changes->record($key, $old, $new, $actor); // 落字段级变更历史（仅有真实变化才写行）

            $applied++;
        }

        return [
            'applied' => $applied,
            'total' => count($rules),
            'skipped' => $skipped,
        ];
    }

    /**
     * 作用：为首次创建的规则决定初始阈值——部分 key 优先读运维 config 的阈值覆盖，其余用 DEFINITIONS 默认。
     *
     * 为什么用 config 覆盖：磁盘/队列/CPU/内存等核心阈值历史上由 ops.alerts.thresholds.* 配置驱动，
     * 这里保持向后兼容：新建规则时若 config 有值就采纳，让老部署的自定义阈值平滑迁移进规则表。
     *
     * @param  string  $key  规则 key
     * @param  array<string, mixed>  $definition  该 key 的 DEFINITIONS 定义（提供默认阈值兜底）
     * @return array{warning_threshold:mixed, critical_threshold:mixed} 初始阈值对
     */
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
            // 其余规则无 config 覆盖，直接采用 DEFINITIONS 里的出厂默认阈值。
            default => [
                'warning_threshold' => $definition['warning_threshold'],
                'critical_threshold' => $definition['critical_threshold'],
            ],
        };
    }
}
