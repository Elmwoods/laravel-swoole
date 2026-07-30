<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\RedisMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Redis 监控控制器（Ops Center）
 */
class RedisMonitorController extends Controller
{
    use ApiResponse;

    /**
     * Redis 原始 INFO
     */
    public function info(RedisMonitorService $service): JsonResponse
    {
        return $this->success(
            $service->getInfo()
        );
    }

    /**
     * Redis Dashboard 汇总数据
     */
    public function summary(RedisMonitorService $service): JsonResponse
    {
        return $this->success(
            $service->getSummary()
        );
    }

    /**
     * Redis 命中率（可用于告警）
     */
    public function hitRate(RedisMonitorService $service): JsonResponse
    {
        return $this->success([
            'hit_rate' => $service->getHitRate(),
        ]);
    }
}
