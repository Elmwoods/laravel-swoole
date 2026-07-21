<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OpsMetricSampleService;
use App\Services\Ops\SystemMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统监控控制器
 */
class SystemMonitorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SystemMonitorService $service
    ) {}

    /**
     * 系统状态
     */
    public function summary()
    {
        return $this->success(
            $this->service->summary()
        );
    }

    /**
     * 系统指标多天趋势（按天平均）。
     */
    public function metricsTrend(Request $request, OpsMetricSampleService $samples): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            'buckets' => $samples->trend($days),
        ]);
    }
}
