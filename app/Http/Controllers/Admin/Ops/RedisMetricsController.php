<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OpsRedisMetricSampleService;
use App\Services\Ops\RedisMetricsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Redis 指标多天趋势（按天平均）。
     */
    public function trend(Request $request, OpsRedisMetricSampleService $samples): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            'buckets' => $samples->trend($days),
        ]);
    }
}
