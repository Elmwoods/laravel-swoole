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
        'fingerprint',
        'source',
        'severity',
        'title',
        'message',
        'context',
        'status',
        'hit_count',
        'last_seen_at',
        'acknowledged_at',
        'acknowledged_by',
        'acknowledge_note',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
