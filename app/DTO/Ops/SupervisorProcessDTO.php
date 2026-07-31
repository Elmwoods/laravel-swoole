<?php

namespace App\DTO\Ops;

/**
 * Supervisor 进程状态 DTO。
 *
 * 承载 Supervisor 管理的单个受控进程（如队列 worker、常驻脚本）的名称、
 * 状态与描述，供运维面板列表展示。
 */
class SupervisorProcessDTO
{
    public function __construct(
        public string $name,         // 进程/程序名（Supervisor program 名称）
        public string $status,       // 进程状态（RUNNING / STOPPED / FATAL 等）
        public string $description,  // 状态描述（如运行时长、退出原因）
    ) {}

    /**
     * 转为前端/接口用的数组。
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'description' => $this->description,
        ];
    }
}
