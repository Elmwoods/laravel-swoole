<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\SystemMonitorService;
use App\Traits\ApiResponse;

/**
 * 系统监控控制器
 */
class SystemMonitorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SystemMonitorService $service
    ) {}

    /**
     * 系统状态
     */
    public function summary()
    {
        return $this->success(
            $this->service->summary()
        );
    }
}
