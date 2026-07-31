<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center 告警评估记录模型。
 *
 * 每次告警评估引擎运行（定时或手动）落一条记录，用于审计与展示：
 * 本轮检测出多少告警、自动解决多少、耗时多久等。
 */
class OpsAlertEvaluation extends Model
{
    protected $fillable = [
        'trigger',              // 触发方式：schedule（定时）/ manual（手动）等
        'status',               // 本轮评估结果状态（成功/失败）
        'detected_count',       // 本轮新检测到的告警数量
        'auto_resolved_count',  // 本轮自动解决（恢复）的告警数量
        'started_at',           // 评估开始时间
        'finished_at',          // 评估结束时间
        'duration_ms',          // 评估耗时（毫秒）
        'message',              // 备注/错误信息
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',   // 起止时间转 Carbon 便于计算耗时
            'finished_at' => 'datetime',
        ];
    }
}
