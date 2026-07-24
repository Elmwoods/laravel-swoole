<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\SecurityOverviewService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SecurityOverviewController extends Controller
{
    use ApiResponse;

    public function overview(SecurityOverviewService $service): JsonResponse
    {
        return $this->success($service->overview());
    }
}
