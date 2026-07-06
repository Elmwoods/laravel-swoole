<?php

namespace App\Services\Ops\Docker;

use Illuminate\Support\Facades\Log;

class DockerService
{
    public function __construct(
        private DockerClient $client
    ) {}

    /**
     * 获取容器列表（按名称排序，便于阅读）
     */
    public function containers(): array
    {
        try {
            $data = $this->client->get('/containers/json', [
                'all' => 1   // 包括停止的容器
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
                    'id'      => substr($c['Id'], 0, 12),
                    'full_id' => $c['Id'],
                    'name'    => ltrim($c['Names'][0] ?? '', '/'),
                    'image'   => $c['Image'],
                    'state'   => $c['State'],
                    'status'  => $c['Status'],
                ];
            })
            ->sortBy('name')          // 按容器名排序
            ->values()
            ->all();
    }

    /**
     * Docker 容器状态汇总。
     *
     * 前端首页/容器页只需要展示运行、停止、异常数量时使用该接口，
     * 避免在浏览器端重复遍历和判断容器状态。
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
            'unhealthy' => collect($containers)
                ->filter(fn (array $container): bool => str_contains(strtolower($container['status']), 'unhealthy'))
                ->count(),
            'containers' => $containers,
            'available' => true,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 获取容器日志（原始文本，已自动去除 Docker 帧头）
     *
     * @param string $containerId 容器 ID 或名称
     * @param int $tail 返回最后多少行，默认 20
     * @return string 纯日志文本
     */
    public function logs(string $containerId, int $tail = 20): string
    {
        try {
            // Docker logs API 返回的是带有 8 字节帧头（stream type + 长度）的数据
            // 我们需要去除帧头，只保留实际日志内容
            $raw = $this->client->getRaw("/containers/{$containerId}/logs", [
                'stdout' => true,
                'stderr' => true,
                'tail'   => $tail,
            ]);

            return $this->stripDockerLogHeaders($raw);
        } catch (\Exception $e) {
            Log::error("Failed to fetch logs for container {$containerId}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * 获取容器实时资源统计（单次快照）
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
     * 重启容器
     */
    public function restart(string $containerId): array
    {
        return $this->client->post("/containers/{$containerId}/restart");
    }

    /**
     * 停止容器
     */
    public function stop(string $containerId): array
    {
        return $this->client->post("/containers/{$containerId}/stop");
    }

    /**
     * 启动容器
     */
    public function start(string $containerId): array
    {
        return $this->client->post("/containers/{$containerId}/start");
    }

    /**
     * 获取 Docker 版本信息
     */
    public function version(): array
    {
        return $this->client->get('/version');
    }

    /**
     * 移除 Docker logs API 返回的帧头（8 字节）
     * 格式：[1 byte stream type][3 bytes padding][4 bytes size] + data
     *
     * @param string $raw
     * @return string
     */
    private function stripDockerLogHeaders(string $raw): string
    {
        $result = '';
        $offset = 0;
        $length = strlen($raw);

        while ($offset + 8 <= $length) {
            // 跳过前8字节头，读取后面的实际数据
            $chunkSize = unpack('N', substr($raw, $offset + 4, 4))[1];
            $offset += 8;
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
     * 计算 Docker CPU 使用率。
     *
     * Docker stats 原始字段需要通过两次快照差值计算。这里直接返回百分比，
     * 前端无需理解 Docker Engine 的 cpu_stats 结构。
     */
    private function cpuPercent(array $stats): float
    {
        $cpuDelta = (float) data_get($stats, 'cpu_stats.cpu_usage.total_usage', 0)
            - (float) data_get($stats, 'precpu_stats.cpu_usage.total_usage', 0);

        $systemDelta = (float) data_get($stats, 'cpu_stats.system_cpu_usage', 0)
            - (float) data_get($stats, 'precpu_stats.system_cpu_usage', 0);

        $onlineCpus = (int) data_get(
            $stats,
            'cpu_stats.online_cpus',
            count((array) data_get($stats, 'cpu_stats.cpu_usage.percpu_usage', [])) ?: 1
        );

        if ($cpuDelta <= 0 || $systemDelta <= 0) {
            return 0.0;
        }

        return round(($cpuDelta / $systemDelta) * $onlineCpus * 100, 2);
    }

    /**
     * 归一化内存统计。
     */
    private function memoryStats(array $stats): array
    {
        $usage = (int) data_get($stats, 'memory_stats.usage', 0);
        $cache = (int) data_get($stats, 'memory_stats.stats.cache', 0);
        $limit = (int) data_get($stats, 'memory_stats.limit', 0);
        $realUsage = max($usage - $cache, 0);

        return [
            'usage_bytes' => $realUsage,
            'usage_mb' => round($realUsage / 1024 / 1024, 2),
            'limit_bytes' => $limit,
            'limit_mb' => round($limit / 1024 / 1024, 2),
            'percent' => $limit > 0 ? round(($realUsage / $limit) * 100, 2) : 0.0,
        ];
    }

    /**
     * 汇总容器网络 IO。
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
     * 汇总容器磁盘读写 IO。
     */
    private function blockIoStats(array $stats): array
    {
        $entries = (array) data_get($stats, 'blkio_stats.io_service_bytes_recursive', []);
        $read = 0;
        $write = 0;

        foreach ($entries as $entry) {
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
     * Docker API 不可用时的安全兜底结构。
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
