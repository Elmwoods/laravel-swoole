<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\RedisMetricsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Redis 实时图表控制器
 */
class RedisMetricsController extends Controller
{
    use ApiResponse;

    /**
     * 推送采样数据（5秒一次）
     */
    public function push(RedisMetricsService $service): JsonResponse
    {
        return $this->success(
            $service->push()
        );
    }

    /**
     * 获取图表数据
     */
    public function chart(RedisMetricsService $service): JsonResponse
    {
        return $this->success(
            $service->chart()
        );
    }
}
