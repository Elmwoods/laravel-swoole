<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ops Center 值班交接记录模型。
 *
 * 记录一次值班交班：从谁交给谁、交接备注，以及交接时仍未关闭的告警数量快照，
 * 用于值班轮转的责任衔接与审计。
 */
class OpsShiftHandover extends Model
{
    protected $fillable = [
        'from_assignee',     // 交班人（上一班处理人）
        'to_assignee',       // 接班人（下一班处理人）
        'note',              // 交接备注（遗留问题、注意事项等）
        'open_alert_count',  // 交接时仍处于 open 状态的告警数量快照
        'created_by',        // 记录创建者后台用户 ID
    ];

    protected function casts(): array
    {
        return [
            'open_alert_count' => 'integer',   // 未关闭告警数为整数
        ];
    }

    /**
     * 记录创建者后台用户（多对一，外键 created_by）。
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
