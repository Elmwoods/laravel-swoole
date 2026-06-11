<?php

namespace App\Services\Ops;

use App\DTO\Ops\OctaneStatusDTO;

class OctaneMonitorService
{
    /**
     * 获取 Octane 状态
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
