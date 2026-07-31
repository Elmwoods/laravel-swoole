<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 后台 IP 访问规则模型。
 *
 * 定义黑/白名单 IP 或网段（CIDR），控制后台访问来源，支持手动录入或
 * 自动封禁产生，可设置过期时间实现临时封禁。
 */
class AdminIpRule extends Model
{
    protected $fillable = [
        'type',        // 规则类型：allow（放行）/ block（拦截）
        'cidr',        // IP 或 CIDR 网段（如 1.2.3.4 或 10.0.0.0/8）
        'label',       // 规则说明/备注
        'is_active',   // 是否启用
        'created_by',  // 创建者后台用户 ID（可空，自动封禁时为空）
        'expires_at',  // 过期时间（可空，为空表示永久；用于临时封禁）
        'source',      // 来源：manual（手动）/ auto_ban（自动封禁）等
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',    // 启用开关转布尔
            'expires_at' => 'datetime',  // 过期时间转 Carbon，便于判断是否失效
        ];
    }

    /**
     * 创建者后台用户（多对一，外键 created_by）。
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
