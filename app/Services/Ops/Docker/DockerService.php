<?php

namespace App\Services\Ops\Docker;

use Illuminate\Support\Facades\Log;

/**
 * Docker 容器业务服务层。
 *
 * 在 Ops Center 中，本类位于 DockerClient（底层 HTTP 通道）之上，负责把
 * Docker Engine 返回的原始数据翻译成前端友好的结构：容器列表、状态汇总、
 * 日志、CPU/内存/网络/磁盘统计、以及启停/重启动作。
 * 所有对 Docker API 的调用都包裹了 try/catch，Docker 不可用时返回安全兜底结构，
 * 保证运维大盘不会因为 Docker 掉线而整体崩溃。
 */
class DockerService
{
    /**
     * 作用：注入底层 Docker HTTP 客户端。
     *
     * @param  DockerClient  $client  负责实际与 Docker Engine 通信的底层客户端
     */
    public function __construct(
        private DockerClient $client
    ) {}

    /**
     * 作用：获取全部容器列表（含已停止的），并归一化为前端所需字段。
     *
     * 获取容器列表（按名称排序，便于阅读）
     *
     * @return array 容器信息数组，每项含 id/full_id/name/image/state/status
     *
     * 为什么：捕获所有异常并返回空数组，使得 Docker 掉线时列表页仍能正常渲染。
     */
    public function containers(): array
    {
        try {
            $data = $this->client->get('/containers/json', [
                'all' => 1,   // 包括停止的容器
            ]);
        } catch (\Throwable $e) {
            Log::error('Docker containers fetch failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return collect($data)
            ->map(function ($c) {
                return [
                    // Docker 短 ID：取完整 ID 前 12 位
                    'id' => substr($c['Id'], 0, 12),
                    'full_id' => $c['Id'],
                    // Docker 返回的容器名带前导斜杠（如 "/web"），去掉更符合展示习惯
                    'name' => ltrim($c['Names'][0] ?? '', '/'),
                    'image' => $c['Image'],
                    'state' => $c['State'],
                    'status' => $c['Status'],
                ];
            })
            ->sortBy('name')          // 按容器名排序
            ->values()
            ->all();
    }

    /**
     * 作用：汇总容器总数、运行数、停止数、异常数，供大盘卡片展示。
     *
     * Docker 容器状态汇总。
     *
     * 前端首页/容器页只需要展示运行、停止、异常数量时使用该接口，
     * 避免在浏览器端重复遍历和判断容器状态。
     *
     * @return array 含 total/running/exited/unhealthy/containers/available/checked_at
     *
     * 为什么：把状态判断集中到后端，前端只消费数字，避免浏览器端重复遍历逻辑分散。
     */
    public function summary(): array
    {
        $containers = $this->containers();

        $running = collect($containers)
            ->where('state', 'running')
            ->count();

        $exited = collect($containers)
            ->where('state', 'exited')
            ->count();

        return [
            'total' => count($containers),
            'running' => $running,
            'exited' => $exited,
            // Docker 健康检查失败时，status 文本里会含 "(unhealthy)"，据此统计异常数
            'unhealthy' => collect($containers)
                ->filter(fn (array $container): bool => str_contains(strtolower($container['status']), 'unhealthy'))
                ->count(),
            'containers' => $containers,
            'available' => true,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 作用：拉取指定容器最近若干行日志，并去除 Docker 流帧头返回纯文本。
     *
     * 获取容器日志（原始文本，已自动去除 Docker 帧头）
     *
     * @param  string  $containerId  容器 ID 或名称
     * @param  int  $tail  返回最后多少行，默认 20
     * @return string 纯日志文本
     *
     * 为什么：Docker logs 端点返回带 8 字节二进制帧头的多路复用流，
     * 必须走 getRaw（不解析 JSON）并调用 stripDockerLogHeaders 剥离帧头才能得到可读文本。
     */
    public function logs(string $containerId, int $tail = 20): string
    {
        try {
            // Docker logs API 返回的是带有 8 字节帧头（stream type + 长度）的数据
            // 我们需要去除帧头，只保留实际日志内容
            $raw = $this->client->getRaw("/containers/{$containerId}/logs", [
                'stdout' => true,
                'stderr' => true,
                'tail' => $tail,
            ]);

            return $this->stripDockerLogHeaders($raw);
        } catch (\Exception $e) {
            Log::error("Failed to fetch logs for container {$containerId}: ".$e->getMessage());

            return '';
        }
    }

    /**
     * 作用：获取容器一次性资源统计快照（CPU/内存/网络/磁盘/进程数）。
     *
     * 获取容器实时资源统计（单次快照）
     *
     * @param  string  $containerId  容器 ID 或名称
     * @return array 归一化后的统计数据；Docker 不可用时返回 emptyStats 兜底结构
     *
     * 为什么：Docker stats 端点默认是持续流式输出，后台轮询必须用 stream=false 拿单帧，否则会挂住连接。
     */
    public function stats(string $containerId): array
    {
        try {
            // stream=false 表示只返回一次快照，不保持长连接，适合后台轮询。
            $stats = $this->client->get("/containers/{$containerId}/stats", [
                'stream' => 'false',
            ]);
        } catch (\Throwable $e) {
            Log::error('Docker stats fetch failed', [
                'container_id' => $containerId,
                'message' => $e->getMessage(),
            ]);

            return $this->emptyStats($containerId, $e->getMessage());
        }

        return [
            'container_id' => $containerId,
            'cpu_percent' => $this->cpuPercent($stats),
            'memory' => $this->memoryStats($stats),
            'network' => $this->networkStats($stats),
            'block_io' => $this->blockIoStats($stats),
            'pids' => (int) data_get($stats, 'pids_stats.current', 0),
            'read_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 作用：重启指定容器。
     *
     * 重启容器
     *
     * @param  string  $containerId  容器 ID 或名称
     * @return array Docker API 响应
     */
    public function restart(string $containerId): array
    {
        return $this->client->post("/containers/{$containerId}/restart");
    }

    /**
     * 作用：停止指定容器。
     *
     * 停止容器
     *
     * @param  string  $containerId  容器 ID 或名称
     * @return array Docker API 响应
     */
    public function stop(string $containerId): array
    {
        return $this->client->post("/containers/{$containerId}/stop");
    }

    /**
     * 作用：启动指定容器。
     *
     * 启动容器
     *
     * @param  string  $containerId  容器 ID 或名称
     * @return array Docker API 响应
     */
    public function start(string $containerId): array
    {
        return $this->client->post("/containers/{$containerId}/start");
    }

    /**
     * 作用：获取 Docker Engine 版本信息。
     *
     * 获取 Docker 版本信息
     *
     * @return array 版本信息数组
     */
    public function version(): array
    {
        return $this->client->get('/version');
    }

    /**
     * 作用：剥离 Docker 多路复用日志流的 8 字节帧头，还原成纯文本日志。
     *
     * 移除 Docker logs API 返回的帧头（8 字节）
     * 格式：[1 byte stream type][3 bytes padding][4 bytes size] + data
     *
     * @param  string  $raw  含帧头的原始日志流
     * @return string 去帧头后的纯日志文本
     *
     * 为什么：Docker 未开 TTY 时会用「stdout/stderr 多路复用」格式，
     * 每段数据前有 8 字节头（第 5-8 字节是大端序的段长度），逐帧解析才能正确拼接文本。
     */
    private function stripDockerLogHeaders(string $raw): string
    {
        $result = '';
        $offset = 0;
        $length = strlen($raw);

        while ($offset + 8 <= $length) {
            // 跳过前8字节头，读取后面的实际数据
            // 'N' = 32 位大端序无符号整数，从第 5 字节起读 4 字节得到该段数据长度
            $chunkSize = unpack('N', substr($raw, $offset + 4, 4))[1];
            // 越过帧头，$offset 指向真正的日志数据
            $offset += 8;
            // 若声明长度超出剩余数据（流被截断），直接把剩余部分全部取出并结束
            if ($offset + $chunkSize > $length) {
                // 数据不完整，直接返回剩余部分
                $result .= substr($raw, $offset);
                break;
            }
            $result .= substr($raw, $offset, $chunkSize);
            $offset += $chunkSize;
        }

        return $result;
    }

    /**
     * 作用：根据 stats 快照中的当前值与上一次值（precpu）计算 CPU 使用百分比。
     *
     * 计算 Docker CPU 使用率。
     *
     * Docker stats 原始字段需要通过两次快照差值计算。这里直接返回百分比，
     * 前端无需理解 Docker Engine 的 cpu_stats 结构。
     *
     * @param  array  $stats  Docker stats 原始快照
     * @return float CPU 使用百分比（保留两位小数）
     *
     * 为什么：Docker 的 CPU 用量是累计值，必须用「本次容器用量差 / 本次系统用量差 × 核心数 × 100」求瞬时占用率；
     * 差值 <=0（首帧无 precpu 或数据异常）时直接返回 0，避免除零和负数。
     */
    private function cpuPercent(array $stats): float
    {
        $cpuDelta = (float) data_get($stats, 'cpu_stats.cpu_usage.total_usage', 0)
            - (float) data_get($stats, 'precpu_stats.cpu_usage.total_usage', 0);

        $systemDelta = (float) data_get($stats, 'cpu_stats.system_cpu_usage', 0)
            - (float) data_get($stats, 'precpu_stats.system_cpu_usage', 0);

        // 优先取 online_cpus；旧版本 Docker 无此字段时退化为 percpu_usage 数组长度，最少按 1 核算
        $onlineCpus = (int) data_get(
            $stats,
            'cpu_stats.online_cpus',
            count((array) data_get($stats, 'cpu_stats.cpu_usage.percpu_usage', [])) ?: 1
        );

        // 首帧无 precpu 或数据异常导致差值非正时，返回 0 避免除零/负值
        if ($cpuDelta <= 0 || $systemDelta <= 0) {
            return 0.0;
        }

        return round(($cpuDelta / $systemDelta) * $onlineCpus * 100, 2);
    }

    /**
     * 作用：把 Docker 原始内存字段归一化为字节/MB/占比结构。
     *
     * 归一化内存统计。
     *
     * @param  array  $stats  Docker stats 原始快照
     * @return array 含 usage_bytes/usage_mb/limit_bytes/limit_mb/percent
     *
     * 为什么：Docker 上报的 usage 含页缓存（cache），扣掉 cache 才是容器真实占用，
     * 这与 `docker stats` 命令行显示口径一致，避免虚高。
     */
    private function memoryStats(array $stats): array
    {
        $usage = (int) data_get($stats, 'memory_stats.usage', 0);
        $cache = (int) data_get($stats, 'memory_stats.stats.cache', 0);
        $limit = (int) data_get($stats, 'memory_stats.limit', 0);
        // 扣除页缓存得到真实占用，max(...,0) 防止个别版本 cache>usage 时出现负数
        $realUsage = max($usage - $cache, 0);

        return [
            'usage_bytes' => $realUsage,
            'usage_mb' => round($realUsage / 1024 / 1024, 2),
            'limit_bytes' => $limit,
            'limit_mb' => round($limit / 1024 / 1024, 2),
            // 仅当 limit>0 才算占比，未设内存上限时避免除零
            'percent' => $limit > 0 ? round(($realUsage / $limit) * 100, 2) : 0.0,
        ];
    }

    /**
     * 作用：累加容器所有网络接口的收发字节，汇总为网络 IO 统计。
     *
     * 汇总容器网络 IO。
     *
     * @param  array  $stats  Docker stats 原始快照
     * @return array 含 rx_bytes/tx_bytes/rx_mb/tx_mb/interfaces
     *
     * 为什么：一个容器可能有多个网络接口（eth0、eth1...），需逐接口累加 rx/tx 才是总流量。
     */
    private function networkStats(array $stats): array
    {
        $interfaces = (array) data_get($stats, 'networks', []);
        $rx = 0;
        $tx = 0;

        foreach ($interfaces as $item) {
            $rx += (int) ($item['rx_bytes'] ?? 0);
            $tx += (int) ($item['tx_bytes'] ?? 0);
        }

        return [
            'rx_bytes' => $rx,
            'tx_bytes' => $tx,
            'rx_mb' => round($rx / 1024 / 1024, 2),
            'tx_mb' => round($tx / 1024 / 1024, 2),
            'interfaces' => $interfaces,
        ];
    }

    /**
     * 作用：从 blkio 明细中分别累加 read/write 字节，汇总为磁盘 IO 统计。
     *
     * 汇总容器磁盘读写 IO。
     *
     * @param  array  $stats  Docker stats 原始快照
     * @return array 含 read_bytes/write_bytes/read_mb/write_mb
     *
     * 为什么：blkio 明细按 (设备, 操作类型) 逐条列出，需按 op 字段区分 read/write 分别求和。
     */
    private function blockIoStats(array $stats): array
    {
        $entries = (array) data_get($stats, 'blkio_stats.io_service_bytes_recursive', []);
        $read = 0;
        $write = 0;

        foreach ($entries as $entry) {
            // op 字段大小写不固定（Read/READ...），统一转小写再比较
            $operation = strtolower((string) ($entry['op'] ?? ''));
            $value = (int) ($entry['value'] ?? 0);

            if ($operation === 'read') {
                $read += $value;
            }

            if ($operation === 'write') {
                $write += $value;
            }
        }

        return [
            'read_bytes' => $read,
            'write_bytes' => $write,
            'read_mb' => round($read / 1024 / 1024, 2),
            'write_mb' => round($write / 1024 / 1024, 2),
        ];
    }

    /**
     * 作用：构造一份「全零 + available=false」的统计结构，作为 Docker 异常时的兜底返回。
     *
     * Docker API 不可用时的安全兜底结构。
     *
     * @param  string  $containerId  容器 ID 或名称
     * @param  string  $message  捕获到的错误信息，透传给前端
     * @return array 与正常 stats 同构、但数值全为 0 的结构
     *
     * 为什么：保持返回结构与正常路径完全一致，前端无需对异常分支做特殊判断，只看 available 字段即可。
     */
    private function emptyStats(string $containerId, string $message): array
    {
        return [
            'container_id' => $containerId,
            'available' => false,
            'error' => $message,
            'cpu_percent' => 0.0,
            'memory' => [
                'usage_bytes' => 0,
                'usage_mb' => 0,
                'limit_bytes' => 0,
                'limit_mb' => 0,
                'percent' => 0,
            ],
            'network' => [
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'rx_mb' => 0,
                'tx_mb' => 0,
                'interfaces' => [],
            ],
            'block_io' => [
                'read_bytes' => 0,
                'write_bytes' => 0,
                'read_mb' => 0,
                'write_mb' => 0,
            ],
            'pids' => 0,
            'read_at' => now()->toDateTimeString(),
        ];
    }
}
