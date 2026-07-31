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
     * 作用：按配置的队列名逐个统计 Redis 中 pending/delayed/reserved 任务数。
     *
     * 获取 Redis Queue 基本信息。
     *
     * @return array 每项含 name/pending/delayed/reserved
     *
     * 为什么：Laravel 队列在 Redis 中用固定键结构存储——待处理任务是 list（llen），
     * 延迟与保留任务是 sorted set（zcard），因此三种计数用不同 Redis 命令。
     */
    public function getRedisQueues(): array
    {
        $redis = Redis::connection();
        // 读取需监控的队列名列表，未配置时默认只看 default 队列
        $queues = config('ops.queues.names', ['default']);

        return collect($queues)
            ->map(function (string $queue) use ($redis): array {
                // Laravel Redis 队列的键前缀约定
                $baseKey = "queues:{$queue}";

                // pending=list 长度(llen)；delayed/reserved=有序集合基数(zcard)
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
     * 作用：统计失败任务总数并取最近 5 条失败记录。
     *
     * failed_jobs 统计。
     *
     * @return array 含 count（失败总数）与 latest（最近 5 条）
     *
     * 为什么：先判断 failed_jobs 表是否存在，未跑迁移的环境不会因缺表报错。
     */
    public function getFailedJobs(): array
    {
        // failed_jobs 表可能尚未迁移，缺表时返回空统计避免异常
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
     * 作用：汇总队列 worker 运行状态（连接、是否运行、进程数与明细）。
     *
     * worker 状态。
     *
     * @return array 含 queue_connection/running/process_count/processes
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
     * 作用：一次性汇总队列、失败任务、worker 状态，供监控大盘调用。
     *
     * Dashboard 汇总。
     *
     * @return array 含 queues/failed_jobs/workers/checked_at
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
     * 作用：对 Redis 执行指定计数命令，异常时安全返回 0。
     *
     * 读取 Redis 计数，Redis 不可用时返回 0，避免监控页整体崩溃。
     *
     * @param  mixed  $redis  Redis 连接实例
     * @param  string  $method  要调用的 Redis 命令名（llen / zcard 等）
     * @param  string  $key  目标键名
     * @return int 计数结果；异常时为 0
     *
     * 为什么：用变量方法名 `$redis->{$method}($key)` 复用同一套 try/catch，
     * 任一队列键读取失败都不影响其他键与整页渲染。
     */
    private function redisCount(mixed $redis, string $method, string $key): int
    {
        try {
            return (int) $redis->{$method}($key);
        } catch (\Throwable) {
            // Redis 掉线/键类型异常时兜底为 0
            return 0;
        }
    }

    /**
     * 作用：扫描系统进程，筛选出 queue:work / queue:listen 的 worker 进程。
     *
     * 从系统进程中查找 Laravel Queue Worker。
     *
     * @return array 匹配到的 worker 进程数组；ps 失败时返回空数组
     *
     * 为什么：与 Octane 采集同理，用 `ps -o 字段=` 无表头输出 + PHP 内过滤，
     * 规避 grep 管道的跨平台差异与误匹配。
     */
    private function queueWorkerProcesses(): array
    {
        $process = new Process([
            'ps',
            'ax',
            '-o',
            'pid=,pcpu=,pmem=,etime=,command=',
        ]);

        // 进程扫描限时 3 秒，防止阻塞监控请求
        $process->setTimeout(3);
        $process->run();

        // ps 执行失败时安全返回空列表
        if (! $process->isSuccessful()) {
            return [];
        }

        return collect(explode(PHP_EOL, trim($process->getOutput())))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): array => $this->parseWorkerLine($line))
            ->filter(function (array $row): bool {
                // 命令转小写再做包含匹配
                $command = strtolower($row['command']);

                // 同时覆盖 queue:work（常驻）与 queue:listen（每次重启）两种运行方式
                return str_contains($command, 'queue:work')
                    || str_contains($command, 'queue:listen');
            })
            ->values()
            ->all();
    }

    /**
     * 作用：把一行 ps 输出按空白切成 5 段，解析为 worker 进程信息。
     *
     * 解析 queue worker 进程信息。
     *
     * @param  string  $line  单行 ps 输出
     * @return array 含 pid/cpu_percent/memory_percent/running_time/command
     *
     * 为什么：limit=5 保证末段 command 内部空格不被继续拆分，命令行能完整保留。
     */
    private function parseWorkerLine(string $line): array
    {
        // 按连续空白切分，最多 5 段：最后一段保留完整命令行
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
