<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center 告警规则模型。
 *
 * 定义某个采集指标（metric）在何种比较条件（operator）与阈值下触发
 * warning / critical 级别告警，是告警评估引擎的配置来源。
 */
class OpsAlertRule extends Model
{
    protected $fillable = [
        'key',                 // 规则唯一键（程序内引用，稳定不变）
        'name',                // 规则展示名称
        'source',              // 关联的采集来源（system/redis 等）
        'metric',              // 被监控的指标字段名
        'operator',            // 比较操作符（如 > < >= 等），指标与阈值的比较方式
        'warning_threshold',   // warning 级阈值
        'critical_threshold',  // critical 级阈值
        'unit',                // 阈值单位（% / MB / ms 等），仅用于展示
        'is_active',           // 是否启用该规则
        'description',         // 规则说明
        'sort_order',          // 列表排序权重
    ];

    protected function casts(): array
    {
        return [
            'warning_threshold' => 'float',   // 阈值为浮点数，支持小数比较
            'critical_threshold' => 'float',
            'is_active' => 'boolean',         // 启用开关转布尔
            'sort_order' => 'integer',        // 排序权重为整数
        ];
    }
}
