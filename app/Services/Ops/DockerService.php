<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\ConnectException;

class DockerService
{
    /**
     * Docker Socket地址
     */
    protected string $socketPath = '/var/run/docker.sock';

    /**
     * 创建 Docker API Client
     */
    protected function client()
    {
        return Http::withOptions([
            'curl' => [
                CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            ],
        ])
            ->baseUrl('http://localhost')   // host 填什么都可以，但必须存在
            ->timeout(5);
    }

    /**
     * Docker版本信息
     */
    public function version(): array
    {
        return $this->client()
            ->get('/version')
            ->json();
    }

    /**
     * Docker系统信息
     */
    public function info(): array
    {
        return $this->client()
            ->get('/info')
            ->json();
    }

    /**
     * 获取所有容器
     */
    public function containers(bool $all = true): array
    {
        try {

            $containers = $this->client()
                ->get('/containers/json', [
                    'all' => $all ? 1 : 0,
                ])->json();

            return collect($containers)
                ->map(fn($container) => [
                    'id' => substr($container['Id'], 0, 12),

                    'full_id' => $container['Id'],

                    'name' => ltrim(
                        $container['Names'][0] ?? '',
                        '/'
                    ),

                    'image' => $container['Image'],

                    'state' => $container['State'],

                    'status' => $container['Status'],

                    'created_at' => date(
                        'Y-m-d H:i:s',
                        $container['Created']
                    ),

                    'ports' => $container['Ports'] ?? [],
                ])
                ->values()
                ->all();
        } catch (ConnectException $e) {
            throw new \RuntimeException('无法连接到 Docker socket，请检查权限：' . $e->getMessage());
        }
    }

    /**
     * 容器详情
     */
    public function inspect(string $id): array
    {
        return $this->client()
            ->get("/containers/{$id}/json")
            ->json();
    }

    /**
     * 容器资源统计
     */
    public function stats(string $id): array
    {
        $stats = $this->client()
            ->get("/containers/{$id}/stats", [
                'stream' => false,
            ])
            ->json();

        $cpuDelta =
            data_get(
                $stats,
                'cpu_stats.cpu_usage.total_usage',
                0
            )
            -
            data_get(
                $stats,
                'precpu_stats.cpu_usage.total_usage',
                0
            );

        $systemDelta =
            data_get(
                $stats,
                'cpu_stats.system_cpu_usage',
                0
            )
            -
            data_get(
                $stats,
                'precpu_stats.system_cpu_usage',
                0
            );

        $onlineCpus =
            data_get(
                $stats,
                'cpu_stats.online_cpus',
                1
            );

        $cpuPercent = 0;

        if ($systemDelta > 0) {
            $cpuPercent =
                ($cpuDelta / $systemDelta)
                * $onlineCpus
                * 100;
        }

        $memoryUsage =
            data_get(
                $stats,
                'memory_stats.usage',
                0
            );

        $memoryLimit =
            data_get(
                $stats,
                'memory_stats.limit',
                1
            );

        return [
            'cpu_percent' => round(
                $cpuPercent,
                2
            ),

            'memory_used_mb' => round(
                $memoryUsage / 1024 / 1024,
                2
            ),

            'memory_limit_mb' => round(
                $memoryLimit / 1024 / 1024,
                2
            ),

            'memory_percent' => round(
                ($memoryUsage / $memoryLimit) * 100,
                2
            ),

            'networks' => data_get(
                $stats,
                'networks',
                []
            ),
        ];
    }

    /**
     * 获取日志
     */
    public function logs(
        string $id,
        int    $tail = 200
    ): string
    {

        $logs = $this->client()
            ->get(
                "/containers/{$id}/logs",
                [
                    'stdout' => 1,
                    'stderr' => 1,
                    'tail' => $tail,
                    'timestamps' => 1,
                ]
            )
            ->body();

        $logs = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            '',
            $logs
        );

        return mb_convert_encoding(
            $logs,
            'UTF-8',
            'UTF-8'
        );
    }

    public function start(string $id): bool
    {
        return $this->client()
            ->post("/containers/{$id}/start")
            ->successful();
    }

    public function stop(string $id): bool
    {
        return $this->client()
            ->post("/containers/{$id}/stop")
            ->successful();
    }

    public function restart(string $id): bool
    {
        return $this->client()
            ->post("/containers/{$id}/restart")
            ->successful();
    }
}
