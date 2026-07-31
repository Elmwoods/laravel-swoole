<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center 告警规则变更历史模型。
 *
 * 记录 OpsAlertRule 某个字段被谁改成了什么值，用于规则配置的审计追溯。
 * 每条记录对应一次字段级变更，属于只增不改的历史流水。
 */
class OpsAlertRuleChange extends Model
{
    // 变更历史为一次性写入流水，无更新语义，关闭自动时间戳，仅手动写 created_at
    public $timestamps = false;

    protected $fillable = [
        'rule_key',    // 被修改的规则键（对应 OpsAlertRule.key）
        'field',       // 发生变更的字段名
        'old_value',   // 变更前的值（字符串快照）
        'new_value',   // 变更后的值（字符串快照）
        'actor',       // 操作人标识
        'created_at',  // 变更发生时间（手动写入，见上 $timestamps = false）
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',   // 手动维护的时间列仍转 Carbon
        ];
    }
}
