<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center 系统指标采样点模型。
 *
 * 按时间序列存储主机 CPU / 负载 / 内存 / Swap 使用率快照，
 * 用于监控面板的趋势曲线与告警规则评估。
 */
class OpsMetricSample extends Model
{
    protected $fillable = [
        'cpu_load',              // CPU 使用率（百分比）
        'load1',                 // 1 分钟平均负载
        'memory_used_percent',   // 内存使用率（百分比）
        'swap_used_percent',     // Swap 使用率（百分比）
        'captured_at',           // 采样时刻
    ];

    protected function casts(): array
    {
        return [
            'cpu_load' => 'float',              // 采样值均为浮点，保留小数精度
            'load1' => 'float',
            'memory_used_percent' => 'float',
            'swap_used_percent' => 'float',
            'captured_at' => 'datetime',        // 采样时间转 Carbon 便于按时间聚合
        ];
    }
}
