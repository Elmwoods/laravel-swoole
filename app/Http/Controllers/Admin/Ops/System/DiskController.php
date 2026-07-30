<?php

namespace App\Http\Controllers\Admin\Ops\System;

use App\Http\Controllers\Controller;
use App\Services\Ops\System\DiskService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * DiskController
 * -------------------------------------------------
 * 提供磁盘监控 API
 * -------------------------------------------------
 */
class DiskController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DiskService $diskService
    ) {}

    /**
     * 获取磁盘使用率
     *
     * GET /admin/ops/system/disk
     */
    public function index(): JsonResponse
    {
        return $this->success($this->diskService->summary());
    }
}
