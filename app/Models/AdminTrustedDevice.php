<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 后台受信任设备模型。
 *
 * 记录用户"记住此设备"后可跳过二次验证（2FA）的设备令牌，带过期时间。
 * 仅存令牌哈希，明文永不落库。
 */
class AdminTrustedDevice extends Model
{
    protected $fillable = [
        'admin_user_id',    // 所属后台用户 ID
        'token_hash',       // 设备信任令牌哈希（仅存哈希，不可逆）
        'label',            // 设备标签（便于用户识别）
        'last_ip',          // 最近一次使用的 IP
        'last_user_agent',  // 最近一次使用的 UA
        'expires_at',       // 信任过期时间（过期后需重新做 2FA）
    ];

    // 令牌哈希属于敏感字段，序列化输出时隐藏
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',   // 过期时间转 Carbon，供 isExpired 判断
        ];
    }

    /**
     * 所属后台用户（多对一）。
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    /**
     * 判断该受信任设备是否已过期（有过期时间且已过去）。
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
