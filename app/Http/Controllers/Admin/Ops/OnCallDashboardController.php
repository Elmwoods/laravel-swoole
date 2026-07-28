<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OnCallDashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnCallDashboardController extends Controller
{
    use ApiResponse;

    public function overview(Request $request, OnCallDashboardService $service): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 7)));

        return $this->success($service->overview($days));
    }
}
