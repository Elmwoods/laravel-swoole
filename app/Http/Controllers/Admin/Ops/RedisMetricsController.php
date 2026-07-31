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
 *
 * 作用：为运维中心的 Redis 指标可视化提供 HTTP 接口，覆盖三类：
 * 定时推送一份实时采样点（push）、拉取用于绘制实时图表的近期数据（chart）、
 * 以及多天维度的按天平均趋势（trend）。
 * 实时采样/图表由 RedisMetricsService 处理，历史多天趋势由 OpsRedisMetricSampleService 处理。
 *
 * 为什么：实时图表（短期、高频、内存态）与多天趋势（长期、按天聚合、持久化采样）
 * 是两条不同的数据链路，因此分别委托给两个专职服务。
 */
class RedisMetricsController extends Controller
{
    // 引入统一 API 响应 Trait
    use ApiResponse;

    /**
     * 推送采样数据（5秒一次）
     *
     * 作用：采集并写入一份当前时刻的 Redis 指标采样点（供实时图表滚动更新）。
     *
     * @param  RedisMetricsService  $service  Redis 实时指标服务
     * @return JsonResponse 本次采样结果
     *
     * 为什么：前端约每 5 秒调用一次，形成滚动的实时曲线数据源。
     */
    public function push(RedisMetricsService $service): JsonResponse
    {
        // 委托服务采集并写入一份实时采样点
        return $this->success(
            $service->push()
        );
    }

    /**
     * 获取图表数据
     *
     * 作用：返回用于绘制 Redis 实时图表的近期采样序列。
     *
     * @param  RedisMetricsService  $service  Redis 实时指标服务
     * @return JsonResponse 图表数据序列
     */
    public function chart(RedisMetricsService $service): JsonResponse
    {
        // 委托服务返回实时图表所需的采样序列
        return $this->success(
            $service->chart()
        );
    }

    /**
     * Redis 指标多天趋势（按天平均）。
     *
     * 作用：返回最近 N 天的 Redis 指标趋势，按天分桶并取平均。
     *
     * @param  Request  $request  HTTP 请求，读取 days 统计天数
     * @param  OpsRedisMetricSampleService  $samples  Redis 指标采样（持久化）服务，负责按天聚合
     * @return JsonResponse 含 days 与 buckets（按天分桶）的响应
     *
     * 为什么：days 夹取在 [1, 90]（默认 14），限制趋势窗口与聚合成本；
     * 这里使用持久化采样服务而非实时服务，因为多天数据来自落库采样。
     */
    public function trend(Request $request, OpsRedisMetricSampleService $samples): JsonResponse
    {
        // 统计天数归一化到 1~90（默认 14 天）
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            // 委托采样服务按天聚合出多天趋势分桶
            'buckets' => $samples->trend($days),
        ]);
    }
}
