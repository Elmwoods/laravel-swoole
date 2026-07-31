<?php

namespace App\DTO\Ops;

/**
 * Octane / Swoole 运行状态 DTO。
 *
 * 承载应用服务器（Octane 之上的 Swoole）当前的 Worker 规模与运行状态，
 * 供运维面板展示。
 */
class OctaneStatusDTO
{
    public function __construct(
        public int $workers,      // 普通 Worker 进程数
        public int $taskWorkers,  // Task Worker（异步任务）进程数
        public string $status,    // 运行状态（running / stopped 等）
    ) {}

    /**
     * 转为前端/接口用的数组（键名转 snake_case）。
     */
    public function toArray(): array
    {
        return [
            'workers' => $this->workers,
            'task_workers' => $this->taskWorkers,
            'status' => $this->status,
        ];
    }
}
