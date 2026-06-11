<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Redis 指标采样服务（用于图表）
 * ✔ 每5秒采样一次
 * ✔ 存入缓存（避免 Redis INFO 高频调用）
 */
class RedisMetricsService
{
    /**
     * 获取当前 Redis 指标快照
     */
    public function collect(): array
    {
        $info = Redis::connection()->command('info');

        return [
            'time' => now()->format('H:i:s'),

            // 核心监控指标
            'ops' => $info['instantaneous_ops_per_sec'] ?? 0,
            'clients' => $info['connected_clients'] ?? 0,
            'memory' => $info['used_memory'] ?? 0,
            'cpu_sys' => $info['used_cpu_sys'] ?? 0,
            'cpu_user' => $info['used_cpu_user'] ?? 0,
        ];
    }

    /**
     * 写入时间序列缓存（最多保存 60 条 = 5分钟）
     */
    public function push(): array
    {
        $key = 'ops:redis:metrics';

        $data = Cache::get($key, []);

        $data[] = $this->collect();

        // 保留最近 60 条
        if (count($data) > 60) {
            $data = array_slice($data, -60);
        }

        Cache::put($key, $data, 300);

        return $data;
    }

    /**
     * 获取图表数据
     */
    public function chart(): array
    {
        return Cache::get('ops:redis:metrics', []);
    }
}
