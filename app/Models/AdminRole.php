<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 后台角色模型（RBAC 中的角色）。
 *
 * 角色是权限的集合，用户通过被赋予角色间接获得权限。系统内置角色
 * （如 super_admin）以 is_system 标记，通常不允许删除。
 */
class AdminRole extends Model
{
    protected $fillable = [
        'name',         // 角色展示名称
        'slug',         // 角色唯一标识（如 super_admin，鉴权判定用）
        'description',  // 角色说明
        'is_active',    // 是否启用（停用后该角色不参与鉴权）
        'is_system',    // 是否系统内置角色（内置角色一般禁止删除/改动）
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',   // 启用开关转布尔
            'is_system' => 'boolean',   // 内置标记转布尔
        ];
    }

    /**
     * 拥有此角色的后台用户（多对多）。
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class);
    }

    /**
     * 此角色包含的权限（多对多）。
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AdminPermission::class);
    }
}
