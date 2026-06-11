<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 实时监控服务（Octane 兼容版）
 * ✔ 直接使用 Redis INFO array
 * ✔ 不再做字符串解析
 */
class RedisMonitorService
{
    /**
     * 获取 Redis 原始 INFO（已是 array）
     */
    public function getInfo(): array
    {
        return Redis::connection()->command('info');
    }

    /**
     * Dashboard 核心指标（推荐前端使用）
     */
    public function getSummary(): array
    {
        $info = $this->getInfo();

        return [
            // 基础信息
            'redis_version' => $info['redis_version'] ?? null,
            'redis_mode' => $info['redis_mode'] ?? null,
            'role' => $info['role'] ?? null,

            // 运行状态
            'uptime_in_seconds' => $info['uptime_in_seconds'] ?? 0,
            'uptime_in_days' => $info['uptime_in_days'] ?? 0,

            // 连接数
            'connected_clients' => $info['connected_clients'] ?? 0,
            'blocked_clients' => $info['blocked_clients'] ?? 0,
            'rejected_connections' => $info['rejected_connections'] ?? 0,

            // 内存
            'used_memory' => $info['used_memory'] ?? 0,
            'used_memory_human' => $info['used_memory_human'] ?? '0B',
            'used_memory_rss_human' => $info['used_memory_rss_human'] ?? '0B',
            'used_memory_peak_human' => $info['used_memory_peak_human'] ?? '0B',
            'mem_fragmentation_ratio' => $info['mem_fragmentation_ratio'] ?? 0,

            // 性能
            'instantaneous_ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? 0,
            'total_commands_processed' => $info['total_commands_processed'] ?? 0,
            'total_connections_received' => $info['total_connections_received'] ?? 0,

            // 命中率（Redis Cache 很关键）
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0,

            // CPU
            'used_cpu_user' => $info['used_cpu_user'] ?? 0,
            'used_cpu_sys' => $info['used_cpu_sys'] ?? 0,

            // 持久化状态
            'rdb_last_bgsave_status' => $info['rdb_last_bgsave_status'] ?? null,
            'aof_enabled' => $info['aof_enabled'] ?? 0,
        ];
    }

    /**
     * 计算缓存命中率（Ops Center 很关键指标）
     */
    public function getHitRate(): float
    {
        $info = $this->getInfo();

        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;

        $total = $hits + $misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }
}
