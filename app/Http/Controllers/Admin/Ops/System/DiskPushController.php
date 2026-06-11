<?php

namespace App\Http\Controllers\Admin\Ops\System;

use App\Http\Controllers\Controller;
use App\Services\Ops\System\DiskService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 手动触发 WebSocket 推送
 * （后期可改 cron / supervisor）
 */
class DiskPushController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DiskService $service
    ) {}

    public function push(): JsonResponse
    {
        return $this->success($this->service->push());
    }
}
