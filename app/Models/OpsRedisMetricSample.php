<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center Redis 指标采样点模型。
 *
 * 按时间序列存储 Redis 运行指标（吞吐、连接数、内存、命中率）快照，
 * 用于监控面板的 Redis 趋势曲线与告警评估。
 */
class OpsRedisMetricSample extends Model
{
    protected $fillable = [
        'ops',          // 每秒操作数（吞吐量 ops/sec）
        'clients',      // 当前连接的客户端数
        'memory_mb',    // 已用内存（MB）
        'hit_rate',     // 缓存命中率（百分比）
        'captured_at',  // 采样时刻
    ];

    protected function casts(): array
    {
        return [
            'ops' => 'integer',            // 吞吐、连接数为整数
            'clients' => 'integer',
            'memory_mb' => 'float',        // 内存、命中率为浮点
            'hit_rate' => 'float',
            'captured_at' => 'datetime',   // 采样时间转 Carbon 便于按时间聚合
        ];
    }
}
