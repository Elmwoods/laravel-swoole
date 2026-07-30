<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OctaneMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class OctaneMonitorController extends Controller
{
    use ApiResponse;

    public function status(OctaneMonitorService $monitorService): JsonResponse
    {
        return $this->success($monitorService->getStatus()->toArray());
    }
}
