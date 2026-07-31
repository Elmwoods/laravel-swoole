<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 后台操作审计日志模型。
 *
 * 记录后台每一次敏感/关键操作（谁、在哪个模块、做了什么、结果如何、来源 IP 等），
 * 是 Ops Center 安全审计与合规追溯的核心流水表。
 */
class AdminAuditLog extends Model
{
    protected $fillable = [
        'admin_user_id',  // 操作者后台用户 ID（可空，系统操作时为空）
        'admin_email',    // 操作者邮箱（冗余存储，便于用户删除后仍可追溯）
        'module',         // 所属功能模块
        'action',         // 具体动作
        'result',         // 结果：success / failure 等
        'status_code',    // 关联的 HTTP 状态码
        'target_type',    // 被操作对象类型（多态）
        'target_id',      // 被操作对象 ID（多态）
        'payload',        // 操作相关的结构化数据快照，JSON
        'ip_address',     // 来源 IP
        'user_agent',     // 来源 UA
        'message',        // 补充说明/错误信息
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',   // JSON <-> 数组，存操作载荷快照
        ];
    }

    /**
     * 操作者后台用户（多对一，外键 admin_user_id）。
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
