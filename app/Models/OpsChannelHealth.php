<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ops Center 通知通道健康状态模型。
 *
 * 记录每个告警下发通道（telegram / mail / webhook 等）最近一次投递是否成功、
 * 连续失败次数等，用于在界面上标示通道是否可用并驱动熔断/降级逻辑。
 */
class OpsChannelHealth extends Model
{
    // 表名为单数形式，与 Laravel 复数默认不符，显式声明
    protected $table = 'ops_channel_health';

    protected $fillable = [
        'channel',                // 通道标识：telegram / mail / webhook 等
        'status',                 // 当前健康状态：ok / degraded / down 等
        'consecutive_failures',   // 连续失败次数（成功后清零，用于判定熔断）
        'last_ok_at',             // 最近一次成功投递时间
        'last_checked_at',        // 最近一次检测/投递时间
        'last_error',             // 最近一次失败的错误信息
    ];

    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',  // 连续失败计数为整数
            'last_ok_at' => 'datetime',           // 时间列转 Carbon
            'last_checked_at' => 'datetime',
        ];
    }
}
