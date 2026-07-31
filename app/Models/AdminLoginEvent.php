<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 后台登录事件模型。
 *
 * 记录每次后台登录的来源与风险信号（IP、UA、是否可信设备、是否首次出现的
 * IP/UA），用于异常登录检测与安全审计。
 */
class AdminLoginEvent extends Model
{
    // 登录事件是一次性写入流水，只需 created_at；禁用 updated_at 列
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id',      // 登录的后台用户 ID
        'ip_address',         // 登录来源 IP
        'user_agent',         // 登录来源 UA
        'trusted',            // 是否来自受信任设备
        'is_new_ip',          // 该用户是否首次从此 IP 登录（风险信号）
        'is_new_user_agent',  // 该用户是否首次使用此 UA 登录（风险信号）
    ];

    protected function casts(): array
    {
        return [
            'trusted' => 'boolean',            // 布尔标记
            'is_new_ip' => 'boolean',
            'is_new_user_agent' => 'boolean',
            'created_at' => 'datetime',        // 只有创建时间（见上 UPDATED_AT = null），转 Carbon
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
