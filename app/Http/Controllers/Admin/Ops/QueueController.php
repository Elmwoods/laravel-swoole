<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\QueueMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Queue 监控控制器
 */
class QueueController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly QueueMonitorService $service
    ) {}

    /**
     * Queue 总览
     */
    public function summary(): JsonResponse
    {
        return $this->success($this->service->summary());
    }
}
