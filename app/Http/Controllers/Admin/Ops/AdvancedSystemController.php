<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\AdvancedSystemMonitorService;
use App\Traits\ApiResponse;

/**
 * 高级系统监控
 */
class AdvancedSystemController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdvancedSystemMonitorService $service
    ) {}

    public function summary()
    {
        return $this->success(
            $this->service->summary()
        );
    }
}
