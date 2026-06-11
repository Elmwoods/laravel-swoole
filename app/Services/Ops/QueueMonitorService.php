<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

/**
 * Queue 监控服务（Ops Center）
 * ✔ Redis Queue 状态
 * ✔ failed_jobs
 * ✔ worker 基础统计
 */
class QueueMonitorService
{
    /**
     * 获取 Redis Queue 基本信息
     */
    public function getRedisQueues(): array
    {
        $redis = Redis::connection();

        // Laravel 默认 queue key
        $queues = ['default', 'high', 'low'];

        $result = [];

        foreach ($queues as $queue) {
            $key = "queues:{$queue}";

            $result[] = [
                'name' => $queue,
                'pending' => $redis->llen($key), // 队列长度
            ];
        }

        return $result;
    }

    /**
     * failed_jobs 统计
     */
    public function getFailedJobs(): array
    {
        return [
            'count' => DB::table('failed_jobs')->count(),
            'latest' => DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get(),
        ];
    }

    /**
     * worker 状态（简化版 - Supervisor/Octane兼容）
     */
    public function getWorkerStatus(): array
    {
        return [
            'octane' => $this->checkOctane(),
            'queue_connection' => config('queue.default'),
        ];
    }

    /**
     * 检查 Octane 是否运行
     */
    private function checkOctane(): array
    {
        try {
            $pid = shell_exec("pgrep -f 'octane:start'");

            return [
                'running' => !empty($pid),
                'pid' => trim($pid),
            ];
        } catch (\Throwable $e) {
            return [
                'running' => false,
                'pid' => null,
            ];
        }
    }

    /**
     * Dashboard 汇总
     */
    public function summary(): array
    {
        return [
            'queues' => $this->getRedisQueues(),
            'failed_jobs' => $this->getFailedJobs(),
            'workers' => $this->getWorkerStatus(),
        ];
    }
}
