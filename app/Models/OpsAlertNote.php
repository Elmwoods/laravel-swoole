<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ops Center 告警备注模型。
 *
 * 运维人员在某条告警下追加的处理笔记/协作评论，一条告警可有多条备注。
 */
class OpsAlertNote extends Model
{
    protected $fillable = [
        'alert_id',       // 关联的告警 ID
        'admin_user_id',  // 撰写备注的后台用户 ID（可空，兼容系统写入）
        'author',         // 作者显示名（冗余存储，便于用户已删除时仍可展示）
        'body',           // 备注正文
    ];

    /**
     * 所属告警（多对一）。
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(OpsAlert::class);
    }

    /**
     * 撰写备注的后台用户（多对一）。
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
