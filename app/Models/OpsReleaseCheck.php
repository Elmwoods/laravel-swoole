<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ops Center 发布前检查记录模型。
 *
 * 保存一次上线前置检查（release check）的执行结果：整体状态、逐项检查明细、
 * 耗时与起止时间等，用于发布流程的门禁与历史留档。
 */
class OpsReleaseCheck extends Model
{
    protected $fillable = [
        'admin_user_id',  // 触发检查的后台用户 ID（可空）
        'admin_email',    // 触发人邮箱（冗余存储，便于用户删除后仍可追溯）
        'status',         // 检查结果状态：passed / failed / running 等
        'summary',        // 结果摘要（聚合统计），JSON
        'checks',         // 逐项检查明细，JSON
        'duration_ms',    // 检查耗时（毫秒）
        'started_at',     // 开始时间
        'finished_at',    // 结束时间
        'error_message',  // 失败原因（成功时为空）
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',          // JSON <-> 数组
            'checks' => 'array',           // JSON <-> 数组
            'started_at' => 'datetime',    // 起止时间转 Carbon
            'finished_at' => 'datetime',
        ];
    }

    /**
     * 触发检查的后台用户（多对一，外键 admin_user_id）。
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
