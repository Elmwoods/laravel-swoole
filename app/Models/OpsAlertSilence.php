<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ops Center 告警静默（维护窗口）模型。
 *
 * 定义一段时间内对匹配的来源/级别不发送通知的规则，支持一次性时间窗
 * 或按星期几+时段循环的周期性静默（如每天凌晨维护窗口）。
 */
class OpsAlertSilence extends Model
{
    protected $fillable = [
        'label',         // 静默规则名称/说明
        'starts_at',     // 一次性静默的开始时间
        'ends_at',       // 一次性静默的结束时间
        'recurrence',    // 循环方式：none（一次性）/ weekly（按周循环）等
        'days_of_week',  // 循环时生效的星期几集合，JSON 数组
        'start_time',    // 循环时每天生效的起始时刻（HH:MM）
        'end_time',      // 循环时每天生效的结束时刻（HH:MM）
        'sources',       // 匹配的告警来源集合（空=全部），JSON 数组
        'severities',    // 匹配的严重级别集合（空=全部），JSON 数组
        'is_active',     // 是否启用该静默规则
        'created_by',    // 创建者后台用户 ID
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',      // 起止时间转 Carbon
            'ends_at' => 'datetime',
            'days_of_week' => 'array',      // JSON <-> 数组，存星期几
            'sources' => 'array',           // JSON <-> 数组，匹配来源
            'severities' => 'array',        // JSON <-> 数组，匹配级别
            'is_active' => 'boolean',       // 启用开关转布尔
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
