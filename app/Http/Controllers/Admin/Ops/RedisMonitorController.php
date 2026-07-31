<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\RedisMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Redis 监控控制器（Ops Center）
 *
 * 作用：为运维中心提供 Redis 服务器状态的 HTTP 接口，包括原始 INFO 输出、
 * Dashboard 汇总数据，以及可用于告警的缓存命中率。
 * 具体的 Redis 连接、INFO 解析与指标计算由 RedisMonitorService 负责。
 *
 * 为什么：与 RedisMetricsController（时序图表/趋势）不同，本控制器面向
 * 「当前快照 + 汇总面板」，关注实时状态而非历史序列。
 */
class RedisMonitorController extends Controller
{
    // 引入统一 API 响应 Trait
    use ApiResponse;

    /**
     * Redis 原始 INFO
     *
     * 作用：返回 Redis INFO 命令的原始/解析后信息（内存、连接、持久化等分区）。
     *
     * @param  RedisMonitorService  $service  Redis 监控服务
     * @return JsonResponse Redis INFO 数据
     */
    public function info(RedisMonitorService $service): JsonResponse
    {
        // 委托服务获取 Redis INFO 信息
        return $this->success(
            $service->getInfo()
        );
    }

    /**
     * Redis Dashboard 汇总数据
     *
     * 作用：返回用于监控面板的 Redis 汇总指标（关键指标提炼后的结果）。
     *
     * @param  RedisMonitorService  $service  Redis 监控服务
     * @return JsonResponse Dashboard 汇总数据
     */
    public function summary(RedisMonitorService $service): JsonResponse
    {
        // 委托服务生成 Dashboard 汇总
        return $this->success(
            $service->getSummary()
        );
    }

    /**
     * Redis 命中率（可用于告警）
     *
     * 作用：返回 Redis 缓存命中率（keyspace hits/misses 计算所得）。
     *
     * @param  RedisMonitorService  $service  Redis 监控服务
     * @return JsonResponse 含 hit_rate 命中率的响应
     *
     * 为什么：命中率单独成接口，便于告警系统按阈值轮询判断缓存健康度。
     */
    public function hitRate(RedisMonitorService $service): JsonResponse
    {
        return $this->success([
            // 委托服务计算命中率（命中/(命中+未命中)）
            'hit_rate' => $service->getHitRate(),
        ]);
    }
}
