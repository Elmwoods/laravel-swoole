<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 后台会话模型。
 *
 * 记录后台用户的活跃登录会话，支持在"设备/会话管理"中查看并远程注销。
 * 仅存会话令牌的哈希，明文永不落库。
 */
class AdminSession extends Model
{
    protected $fillable = [
        'admin_user_id',       // 所属后台用户 ID
        'session_token_hash',  // 会话令牌哈希（仅存哈希，用于校验，不可逆）
        'label',               // 会话标签（如设备名/浏览器）
        'ip_address',          // 会话来源 IP
        'user_agent',          // 会话来源 UA
        'last_activity_at',    // 最近活跃时间（用于判定过期/展示）
        'revoked_at',          // 注销时间（非空表示会话已被吊销）
    ];

    // 令牌哈希属于敏感字段，序列化输出时隐藏，避免泄露
    protected $hidden = [
        'session_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',   // 时间列转 Carbon
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * 所属后台用户（多对一）。
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
