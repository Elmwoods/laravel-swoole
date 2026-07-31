<?php

namespace App\Services\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsAlertRule;

/**
 * Ops Center 告警规则引擎。
 *
 * 只处理轻量摘要数据，避免在规则判断中读取或传播大日志正文。
 *
 * 在告警 raise/通知 notify/广播 broadcast 流水线中的定位：
 * - 本类处于最上游的 raise（产生告警）阶段：吃一份系统监控快照，逐维度比对阈值，
 *   把"越过阈值"的异常翻译成一批标准化的 AlertDTO。
 * - 它只负责"判断并生成"告警 DTO，不做去重落库、也不做 notify/broadcast；
 *   下游（AlertCenterService 等）再拿这些 DTO 去做指纹去重、落库与对外通知。
 * - 阈值来源双通道：优先读数据库里的 OpsAlertRule（可被运营在后台调整/禁用），
 *   规则不存在时回退到 config('ops.alerts.*') 里的历史默认值。
 * 「为什么」只处理轻量摘要：规则判断在热路径上频繁执行，若在此读取大段日志正文，
 *   会放大内存与传播成本，因此约定快照只带聚合后的数值指标。
 */
class AlertRuleEngineService
{
    /**
     * @param  AlertRuleRegistryService  $registry  规则注册表服务，负责把内置默认规则同步进 OpsAlertRule 表
     */
    public function __construct(private readonly AlertRuleRegistryService $registry) {}

    /**
     * 作用：根据一份系统监控快照，逐维度比对阈值并生成告警 DTO 列表。
     *
     * @param  array  $snapshot  系统快照，按维度分组（disk/queue/docker/network/system/redis/mysql/octane/supervisor）
     * @return array<int, AlertDTO> 命中阈值的告警 DTO 列表（已重排为连续索引数组）
     *
     * 「为什么」先 syncDefaults()：确保内置规则已落库，后续各维度 rule() 查询才能读到规则记录（含被运营禁用的状态）。
     */
    public function detect(array $snapshot): array
    {
        // 先把内置默认规则同步进数据库，保证下面各 rule() 能读到规则的启用/阈值状态
        $this->registry->syncDefaults();

        // 各维度独立探测后用扩展运算符摊平合并；(array) 兜底缺失维度为空数组，避免类型错误
        // array_filter 去掉空值，array_values 重排为连续索引（前端/序列化更友好）
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
     * 作用：磁盘阈值告警——逐个挂载点比对使用率，超过 warning 阈值即产生告警。
     *
     * @param  array  $disk  磁盘维度快照，含 disks 列表（每项有 mount/usage/filesystem 等）
     * @return array<int, AlertDTO> 各挂载点越阈产生的告警（可能为空）
     *
     * 「为什么」warning/critical 双阈值：同一维度按严重度分级，达到 critical 阈值升级为 critical 严重度。
     */
    private function diskAlerts(array $disk): array
    {
        // 读取磁盘规则；config 第二参为历史默认阈值，规则不存在时用它兜底
        $rule = $this->rule('disk_usage', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.disk_usage_warning', 85),
            'critical_threshold' => (float) config('ops.alerts.thresholds.disk_usage_critical', 95),
        ]);

        // rule() 返回 null 表示该规则已被运营在后台禁用，直接不产生此维度告警
        if ($rule === null) {
            return [];
        }

        $threshold = (float) $rule['warning_threshold'];
        // critical 阈值缺省时回退为 warning 阈值，保证后续比较不出现 null
        $criticalThreshold = (float) ($rule['critical_threshold'] ?? $threshold);
        $alerts = [];

        foreach ((array) ($disk['disks'] ?? []) as $item) {
            $usage = (float) ($item['usage'] ?? 0);

            // 未达 warning 阈值的挂载点跳过，不产生告警
            if ($usage < $threshold) {
                continue;
            }

            // 达到或超过 critical 阈值升级为 critical，否则为 warning
            $severity = $usage >= $criticalThreshold ? 'critical' : 'warning';
            $mount = (string) ($item['mount'] ?? '-'); // mount 缺省用 '-' 占位，保证标题/context 可读

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
     * 作用：队列堆积与失败任务告警——逐队列比对 pending，并对 failed_jobs 总数比对阈值。
     *
     * @param  array  $queue  队列维度快照，含 queues 列表（每项 name/pending）与 failed_jobs.count
     * @return array<int, AlertDTO> 堆积/失败产生的告警（可能为空）
     *
     * 「为什么」拆两条独立规则：队列堆积（pending）与失败任务（failed_jobs）可被运营分别启用/禁用与调阈。
     */
    private function queueAlerts(array $queue): array
    {
        // 队列堆积规则（pending 阈值）
        $pendingRule = $this->rule('queue_pending', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.queue_pending_warning', 100),
        ]);
        // 失败任务规则（failed_jobs 阈值，默认 1，即出现任何失败即告警）
        $failedRule = $this->rule('failed_jobs', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.failed_jobs_warning', 1),
        ]);
        $alerts = [];

        // pending 规则未禁用时才逐队列检查
        if ($pendingRule !== null) {
            $pendingThreshold = (float) $pendingRule['warning_threshold'];

            foreach ((array) ($queue['queues'] ?? []) as $item) {
                $pending = (float) ($item['pending'] ?? 0);

                // 达到或超过 pending 阈值即产生堆积告警
                if ($pending >= $pendingThreshold) {
                    $queueName = (string) ($item['name'] ?? 'default'); // 队列名缺省视为 default
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

        // 读取失败任务总数；data_get 支持点路径且缺失时回退 0
        $failed = (float) data_get($queue, 'failed_jobs.count', 0);

        // 失败规则未禁用且失败数达到阈值时产生告警
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
     * 作用：Docker 异常容器告警——分别检查 unhealthy（严重）与 exited（警告）容器数。
     *
     * @param  array  $docker  Docker 维度快照，含 unhealthy / exited 计数
     * @return array<int, AlertDTO> 异常容器产生的告警（可能为空）
     *
     * 「为什么」exited 额外有 config 开关：已退出容器有时是正常的一次性任务，允许运营整体关掉这类噪声告警。
     */
    private function dockerAlerts(array $docker): array
    {
        // unhealthy 容器规则：默认阈值 1（出现任一即告警），严重度为 critical
        $unhealthyRule = $this->rule('docker_unhealthy', [
            'warning_threshold' => 1,
            'critical_threshold' => 1,
        ]);
        // exited 容器规则：受 config 开关 docker_exited_enabled 控制（默认开）；关闭时整条规则置 null 不检查
        $exitedRule = (bool) config('ops.alerts.thresholds.docker_exited_enabled', true)
            ? $this->rule('docker_exited', ['warning_threshold' => 1])
            : null;
        $unhealthy = (float) ($docker['unhealthy'] ?? 0);
        $exited = (float) ($docker['exited'] ?? 0);
        $alerts = [];

        // unhealthy 规则未禁用且计数达阈：产生 critical 告警
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

        // exited 规则未被 config 关闭且计数达阈：产生 warning 告警
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
     * 作用：网络吞吐告警——取收/发速率的峰值与阈值比较。
     *
     * @param  array  $network  网络维度快照，含 summary.rx_mb_s / summary.tx_mb_s（MB/s）
     * @return array<int, AlertDTO> 峰值越阈时的告警（0 或 1 条）
     *
     * 「为什么」取 rx/tx 的 max 作为峰值：任一方向吞吐过高都值得关注，用较大者代表当前压力。
     */
    private function networkAlerts(array $network): array
    {
        // 网络吞吐规则，默认阈值 50 MB/s
        $rule = $this->rule('network_mbps', [
            'warning_threshold' => (float) config('ops.alerts.thresholds.network_mbps_warning', 50),
        ]);

        // 规则被禁用则不检查
        if ($rule === null) {
            return [];
        }

        $threshold = (float) $rule['warning_threshold'];
        $rx = (float) data_get($network, 'summary.rx_mb_s', 0); // 接收速率
        $tx = (float) data_get($network, 'summary.tx_mb_s', 0); // 发送速率
        $peak = max($rx, $tx); // 取收发方向的较大者作为峰值

        // 峰值未达阈值不告警
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
     * 作用：系统资源告警——把 CPU 与内存两个百分比指标交给通用的 percentageAlert 处理。
     *
     * @param  array  $system  系统维度快照，含 cpu_percent 与 memory.percent
     * @return array<int, AlertDTO> CPU/内存越阈产生的告警合并列表
     */
    private function systemAlerts(array $system): array
    {
        // CPU 与内存都是百分比阈值，复用 percentageAlert；memory.percent 用点路径取值
        return [
            ...$this->percentageAlert('system_cpu', 'system', 'CPU 使用率过高', (float) ($system['cpu_percent'] ?? 0), 'cpu'),
            ...$this->percentageAlert('system_memory', 'system', '内存使用率过高', (float) data_get($system, 'memory.percent', 0), 'memory'),
        ];
    }

    /**
     * 作用：Redis 连接告警——检测到 Redis 不可连接时产生 critical 告警。
     *
     * @param  array  $redis  Redis 维度快照，含布尔字段 connected
     * @return array<int, AlertDTO> 断连时 1 条告警，否则空
     *
     * 「为什么」用 array_key_exists 判断：快照里没有 connected 键（未采集到该指标）时应保持沉默，
     *   而不是把"缺失"误判为"断连"从而误报。
     */
    private function redisAlerts(array $redis): array
    {
        $rule = $this->rule('redis_connected', ['warning_threshold' => 1]);

        // 三种情况都不告警：规则被禁用 / 快照未含 connected 字段（指标缺失）/ 已连接（正常）
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

    /**
     * 作用：MySQL 连接告警——检测到 MySQL 不可连接时产生 critical 告警。
     *
     * @param  array  $mysql  MySQL 维度快照，含布尔字段 connected
     * @return array<int, AlertDTO> 断连时 1 条告警，否则空
     *
     * 「为什么」用 array_key_exists 判断：同 redisAlerts，指标缺失时保持沉默以免把未采集误报成断连。
     */
    private function mysqlAlerts(array $mysql): array
    {
        $rule = $this->rule('mysql_connected', ['warning_threshold' => 1]);

        // 规则禁用 / 未含 connected 字段 / 已连接，三者任一都不告警
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

    /**
     * 作用：Octane 运行状态告警——未运行或 worker 数不足阈值时产生 critical 告警。
     *
     * @param  array  $octane  Octane 维度快照，含 running（布尔）与 process_count（worker 数）
     * @return array<int, AlertDTO> 异常时 1 条告警，否则空
     *
     * 「为什么」空快照直接返回：$octane === [] 表示根本没采集到 Octane 指标（例如未部署 Octane），
     *   此时不应误报运行异常。
     */
    private function octaneAlerts(array $octane): array
    {
        $rule = $this->rule('octane_running', ['warning_threshold' => 1]);

        // 规则被禁用，或压根没采集到 Octane 指标（空数组）时不告警
        if ($rule === null || $octane === []) {
            return [];
        }

        $running = (bool) ($octane['running'] ?? false);
        $processCount = (float) ($octane['process_count'] ?? 0);

        // 正常态：既在运行、worker 数又达到阈值，则不告警
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

    /**
     * 作用：Supervisor 进程告警——对每个非 RUNNING 状态的受管进程各产生一条 warning 告警。
     *
     * @param  array  $processes  Supervisor 快照，既可直接是进程列表，也可是含 services 键的包裹结构
     * @return array<int, AlertDTO> 各异常进程的告警列表
     *
     * 「为什么」兼容两种入参结构：不同采集器给的快照有的是 {services: [...]}，有的直接是 [...]，
     *   这里做一次归一化，让后续过滤逻辑只面对纯进程列表。
     */
    private function supervisorAlerts(array $processes): array
    {
        $rule = $this->rule('supervisor_process_down', ['warning_threshold' => 1]);

        if ($rule === null) {
            return [];
        }

        // 结构归一化：若快照被包裹在 services 键下，则拆包成纯进程列表
        if (array_key_exists('services', $processes) && is_array($processes['services'])) {
            $processes = $processes['services'];
        }

        return collect($processes)
            ->filter(function (array $process): bool {
                $name = trim((string) ($process['name'] ?? ''));
                // 状态字段名不统一，兼容 status/state 两种键，统一转大写便于比较
                $state = strtoupper(trim((string) ($process['status'] ?? $process['state'] ?? '')));

                // 仅保留：有名字、有状态、且状态不是 RUNNING 的进程（即真正异常的进程）
                return $name !== '' && $state !== '' && $state !== 'RUNNING';
            })
            ->map(function (array $process): AlertDTO {
                $name = trim((string) ($process['name'] ?? ''));
                // 同上做状态归一化；缺省用 'unknown' 占位
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
            ->values() // 过滤后重排索引，避免留下稀疏键
            ->all();
    }

    /**
     * 作用：通用的百分比类指标告警——把一个百分比值与 warning/critical 阈值比较并生成告警。
     *
     * @param  string  $ruleKey  规则键（如 system_cpu / system_memory），用于查库取阈值
     * @param  string  $source  告警来源标识（写入 AlertDTO 的 source）
     * @param  string  $title  告警标题
     * @param  float  $value  当前百分比值
     * @param  string  $target  目标标识（写入 context.target，便于下游去重/定位）
     * @return array<int, AlertDTO> 越阈时 1 条告警，否则空
     *
     * 「为什么」抽成通用方法：CPU、内存等多个维度共享"百分比 vs 双阈值"的判定逻辑，避免重复代码。
     */
    private function percentageAlert(string $ruleKey, string $source, string $title, float $value, string $target): array
    {
        // 百分比类默认 warning 85 / critical 95
        $rule = $this->rule($ruleKey, ['warning_threshold' => 85, 'critical_threshold' => 95]);

        // 规则被禁用，或当前值未达 warning 阈值，均不告警
        if ($rule === null || $value < (float) $rule['warning_threshold']) {
            return [];
        }

        // critical 阈值缺省时回退为 warning 阈值，保证比较不出现 null
        $criticalThreshold = (float) ($rule['critical_threshold'] ?? $rule['warning_threshold']);
        // 达到 critical 阈值升级为 critical，否则 warning
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
     * 作用：按 key 取一条规则的阈值配置，统一处理"规则不存在/被禁用/正常"三态。
     *
     * 规则不存在时使用历史 config 默认值；规则存在但被禁用时返回 null。
     *
     * @param  string  $key  规则键（如 disk_usage / system_cpu）
     * @param  array  $fallback  规则记录不存在时使用的默认阈值数组
     * @return array<string, mixed>|null 正常/回退时返回阈值数组；被运营禁用时返回 null（调用方据此跳过该维度）
     *
     * 「为什么」用 null 与 fallback 区分两种"没有规则记录"的语义：
     *   - 记录不存在 => 说明是老部署尚未落库该规则，回退到 config 历史默认继续告警（保守可用）；
     *   - 记录存在但 is_active=false => 是运营主动关掉的，必须彻底静默（返回 null）。
     */
    private function rule(string $key, array $fallback): ?array
    {
        $rule = OpsAlertRule::query()->where('key', $key)->first();

        // 无该规则记录：回退到调用方给的历史默认阈值（而非静默）
        if ($rule === null) {
            return $fallback;
        }

        // 规则存在但被运营禁用：返回 null，调用方据此完全跳过该维度告警
        if (! $rule->is_active) {
            return null;
        }

        // 规则启用：使用数据库中运营维护的阈值
        return [
            'warning_threshold' => $rule->warning_threshold,
            'critical_threshold' => $rule->critical_threshold,
        ];
    }
}
