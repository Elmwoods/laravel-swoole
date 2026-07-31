<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ops Center 告警事件（时间线）模型。
 *
 * 记录单条告警生命周期中的每次操作（认领、指派、升级、解决等），
 * 作为审计时间线展示。仅记录发生时刻，因此只有 created_at。
 */
class OpsAlertEvent extends Model
{
    // 事件是一次性写入的时间线记录，无更新语义，故关闭自动时间戳，仅手动维护 created_at
    public $timestamps = false;

    protected $fillable = [
        'alert_id',      // 关联的告警 ID
        'action',        // 动作类型：acknowledge / assign / escalate / resolve 等
        'actor',         // 操作人标识
        'from_status',   // 变更前的告警状态
        'to_status',     // 变更后的告警状态
        'note',          // 操作备注
        'metadata',      // 额外结构化数据，JSON
        'created_at',    // 事件发生时间（手动写入，见上 $timestamps = false）
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',        // JSON <-> 数组
            'created_at' => 'datetime',   // 手动维护的时间列仍转 Carbon
        ];
    }

    /**
     * 所属告警（多对一）。
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class, 'alert_id');
    }
}
