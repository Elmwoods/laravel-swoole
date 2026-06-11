<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\NetworkTrafficService;
use Illuminate\Http\JsonResponse;

/**
 * 网络流量监控 Controller
 */
class NetworkController extends Controller
{
    public function __construct(
        protected NetworkTrafficService $service
    ) {}

    /**
     * 获取实时网络流量
     */
    public function index(): JsonResponse
    {
        return $this->success( $this->service->getSpeed());
    }
}
