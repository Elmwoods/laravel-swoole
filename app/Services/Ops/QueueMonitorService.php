<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Queue 实时监控服务
 *
 * 功能：
 * - Redis 队列 pending/delayed/reserved 数量
 * - failed_jobs 失败任务统计
 * - queue:work / queue:listen 进程状态
 */
class QueueMonitorService
{
    /**
     * 获取 Redis Queue 基本信息。
     */
    public function getRedisQueues(): array
    {
        $redis = Redis::connection();
        $queues = config('ops.queues.names', ['default']);

        return collect($queues)
            ->map(function (string $queue) use ($redis): array {
                $baseKey = "queues:{$queue}";

                return [
                    'name' => $queue,
                    'pending' => $this->redisCount($redis, 'llen', $baseKey),
                    'delayed' => $this->redisCount($redis, 'zcard', "{$baseKey}:delayed"),
                    'reserved' => $this->redisCount($redis, 'zcard', "{$baseKey}:reserved"),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * failed_jobs 统计。
     */
    public function getFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'count' => 0,
                'latest' => [],
            ];
        }

        return [
            'count' => DB::table('failed_jobs')->count(),
            'latest' => DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get(['id', 'connection', 'queue', 'failed_at'])
                ->map(fn (object $job): array => (array) $job)
                ->all(),
        ];
    }

    /**
     * worker 状态。
     */
    public function getWorkerStatus(): array
    {
        $processes = $this->queueWorkerProcesses();

        return [
            'queue_connection' => config('queue.default'),
            'running' => count($processes) > 0,
            'process_count' => count($processes),
            'processes' => $processes,
        ];
    }

    /**
     * Dashboard 汇总。
     */
    public function summary(): array
    {
        return [
            'queues' => $this->getRedisQueues(),
            'failed_jobs' => $this->getFailedJobs(),
            'workers' => $this->getWorkerStatus(),
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 读取 Redis 计数，Redis 不可用时返回 0，避免监控页整体崩溃。
     */
    private function redisCount(mixed $redis, string $method, string $key): int
    {
        try {
            return (int) $redis->{$method}($key);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 从系统进程中查找 Laravel Queue Worker。
     */
    private function queueWorkerProcesses(): array
    {
        $process = new Process([
            'ps',
            'ax',
            '-o',
            'pid=,pcpu=,pmem=,etime=,command=',
        ]);

        $process->setTimeout(3);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return collect(explode(PHP_EOL, trim($process->getOutput())))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): array => $this->parseWorkerLine($line))
            ->filter(function (array $row): bool {
                $command = strtolower($row['command']);

                return str_contains($command, 'queue:work')
                    || str_contains($command, 'queue:listen');
            })
            ->values()
            ->all();
    }

    /**
     * 解析 queue worker 进程信息。
     */
    private function parseWorkerLine(string $line): array
    {
        $columns = preg_split('/\s+/', trim($line), 5);

        return [
            'pid' => (int) ($columns[0] ?? 0),
            'cpu_percent' => (float) ($columns[1] ?? 0),
            'memory_percent' => (float) ($columns[2] ?? 0),
            'running_time' => $columns[3] ?? '',
            'command' => $columns[4] ?? '',
        ];
    }
}
