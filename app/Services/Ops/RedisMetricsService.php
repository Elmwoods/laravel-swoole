<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Redis 指标采样服务（用于图表）
 * ✔ 每5秒采样一次
 * ✔ 存入缓存（避免 Redis INFO 高频调用）
 *
 * 作用：为 Ops Center 的实时折线图提供短周期时间序列。采样点写入缓存构成滑动窗口，
 * 供前端轮询绘制近 5 分钟走势。
 * 「为什么」：把采样结果缓存成数组而非每次现拉 INFO，可避免前端高频轮询直接压到 Redis 上。
 */
class RedisMetricsService
{
    /**
     * 作用：采集一条当前 Redis 指标快照（带可读时间戳）。
     *
     * @return array<string, mixed> 单个采样点：time 及 ops/clients/memory/cpu 等标量
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
     * 作用：采集一条新快照并追加到缓存时间序列，维持最多 60 条的滑动窗口后返回全量序列。
     *
     * 「为什么」：60 条 × 5 秒采样间隔 ≈ 5 分钟窗口；超出即从尾部截取，保证内存与图表范围恒定。
     * 缓存 TTL 设为 300 秒，与窗口时长对齐，长时间无采样时序列自然过期清空。
     *
     * @return array<int, array<string, mixed>> 追加后完整的采样序列（升序）
     */
    public function push(): array
    {
        $key = 'ops:redis:metrics';

        $data = Cache::get($key, []);

        $data[] = $this->collect();

        // 保留最近 60 条（约 5 分钟窗口），超出则从尾部截取
        if (count($data) > 60) {
            $data = array_slice($data, -60);
        }

        // TTL 300 秒与窗口时长对齐，停止采样后序列自动过期
        Cache::put($key, $data, 300);

        return $data;
    }

    /**
     * 作用：读取当前缓存中的采样序列，供前端绘制图表。
     *
     * @return array<int, array<string, mixed>> 采样序列；缓存缺失时返回空数组
     */
    public function chart(): array
    {
        return Cache::get('ops:redis:metrics', []);
    }
}
