<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ops Center 值班排班模型。
 *
 * 定义某位处理人（assignee）的值班时段，支持一次性时间窗或按星期几+时段
 * 的周期性排班，用于告警升级时确定当前值班人并触发提醒。
 */
class OpsOnCallShift extends Model
{
    protected $fillable = [
        'assignee',      // 值班处理人标识
        'label',         // 排班说明/名称
        'starts_at',     // 一次性排班的开始时间
        'ends_at',       // 一次性排班的结束时间
        'recurrence',    // 循环方式：none（一次性）/ weekly（按周循环）等
        'days_of_week',  // 循环时生效的星期几集合，JSON 数组
        'start_time',    // 循环时每天的值班起始时刻（HH:MM）
        'end_time',      // 循环时每天的值班结束时刻（HH:MM）
        'is_active',     // 是否启用该排班
        'reminded_at',   // 最近一次值班提醒发送时间（去重防止重复提醒）
        'created_by',    // 创建者后台用户 ID
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',      // 起止时间转 Carbon
            'ends_at' => 'datetime',
            'days_of_week' => 'array',      // JSON <-> 数组，存星期几
            'is_active' => 'boolean',       // 启用开关转布尔
            'reminded_at' => 'datetime',    // 提醒时间转 Carbon
        ];
    }

    /**
     * 创建者后台用户（多对一，外键 created_by）。
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
