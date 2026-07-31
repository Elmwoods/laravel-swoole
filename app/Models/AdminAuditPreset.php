<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 后台审计日志筛选预设模型。
 *
 * 保存后台用户在审计日志页面自定义的筛选条件组合，便于一键复用常用查询视图。
 */
class AdminAuditPreset extends Model
{
    protected $fillable = [
        'admin_user_id',  // 所属后台用户 ID
        'name',           // 预设名称
        'filters',        // 筛选条件集合（模块/动作/时间范围等），JSON
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',   // JSON <-> 数组，存放筛选条件
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
