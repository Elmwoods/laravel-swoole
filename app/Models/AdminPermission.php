<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 后台权限模型（RBAC 中的权限项）。
 *
 * 代表一个可授予角色的细粒度权限点，程序内以 slug 作为鉴权判定依据。
 * 与角色是多对多关系。
 */
class AdminPermission extends Model
{
    protected $fillable = [
        'name',         // 权限展示名称
        'slug',         // 权限唯一标识（程序鉴权用，如 ops.alerts.view）
        'group',        // 分组（用于界面归类展示）
        'description',  // 权限说明
    ];

    /**
     * 拥有此权限的角色（多对多，经中间表关联）。
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class);
    }
}
