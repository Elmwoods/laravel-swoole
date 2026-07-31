<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center 告警模型。
 *
 * 只保存告警摘要和轻量上下文，避免把日志正文、Docker logs 等大文本写入数据库
 * 或通过 WebSocket 推送出去。
 */
class OpsAlert extends Model
{
    protected $fillable = [
        'fingerprint',        // 去重指纹（AlertDTO::fingerprint 生成），相同指纹复用同一条 open 告警
        'source',             // 告警来源（如 system/docker/redis 等采集服务标识）
        'severity',           // 严重级别：critical / warning / info
        'title',              // 告警标题（简短摘要）
        'message',            // 告警正文（截断后的说明文本）
        'context',            // 轻量上下文（target/mount/queue 等定位信息），JSON
        'tags',               // 分类标签数组，JSON
        'status',             // 生命周期状态：open / acknowledged / resolved / suppressed 等
        'hit_count',          // 命中次数：同一指纹重复触发时累加
        'flap_count',         // 抖动次数：短时间内反复 open/resolve 的计数
        'last_seen_at',       // 最近一次命中时间
        'acknowledged_at',    // 认领（确认）时间
        'acknowledged_by',    // 认领人标识
        'acknowledge_note',   // 认领备注
        'assigned_to',        // 指派处理人
        'assigned_at',        // 指派时间
        'escalated_at',       // 升级时间
        'escalation_level',   // 当前升级层级
        'suppressed_at',      // 被静默/抑制的时间
        'flapping_until',     // 抖动抑制截止时间：在此之前判定为抖动不再重复通知
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',            // JSON <-> 数组，便于读取定位字段
            'tags' => 'array',               // JSON <-> 数组
            'last_seen_at' => 'datetime',    // 时间列统一转 Carbon，方便比较/格式化
            'acknowledged_at' => 'datetime',
            'assigned_at' => 'datetime',
            'escalated_at' => 'datetime',
            'escalation_level' => 'integer', // 升级层级为整数
            'suppressed_at' => 'datetime',
            'flap_count' => 'integer',       // 抖动计数为整数
            'flapping_until' => 'datetime',
        ];
    }
}
