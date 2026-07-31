<?php

namespace App\Services\Ops;

use App\DTO\Ops\OctaneStatusDTO;

/**
 * Octane 状态监控服务（轻量 DTO 版）。
 *
 * 在 Ops Center 中，本类提供一个基于配置的 Octane 状态快照，把 worker /
 * task worker 数量与运行状态封装为强类型 DTO 返回给上层（如 API 资源或视图）。
 * 与 OctaneControlService 不同，本类不扫描系统进程，仅从配置读取，作为轻量展示用途。
 */
class OctaneMonitorService
{
    /**
     * 作用：读取 Octane 配置，构造并返回 Octane 状态 DTO。
     *
     * 获取 Octane 状态
     *
     * @return OctaneStatusDTO 含 worker 数、task worker 数与状态的强类型对象
     *
     * 为什么：worker 数量直接来自 octane 配置，status 恒为 'running'，
     * 本方法仅面向轻量展示；如需真实运行态请用 OctaneControlService::status()。
     */
    public function getStatus(): OctaneStatusDTO
    {
        return new OctaneStatusDTO(
            workers: (int) config('octane.workers'),
            taskWorkers: (int) config('octane.task_workers'),
            status: 'running'
        );
    }
}
