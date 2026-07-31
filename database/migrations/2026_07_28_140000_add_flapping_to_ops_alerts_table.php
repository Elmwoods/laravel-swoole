<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为运维告警表 ops_alerts 增加「抖动（flapping）检测」相关列（运维告警——抖动抑制）。
 * 当告警在短时间内反复恢复又触发时判定为抖动，用计数与冷却截止时间抑制其频繁通知。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->unsignedInteger('flap_count')->default(0)->after('hit_count'); // 抖动次数计数，默认 0；用于判定是否进入抖动抑制
            $table->timestamp('flapping_until')->nullable()->after('suppressed_at'); // 抖动抑制截止时间；为空表示当前未处于抖动抑制中
        });
    }

    // down：回滚 up()，从 ops_alerts 删除 flap_count 与 flapping_until 两列
    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn(['flap_count', 'flapping_until']);
        });
    }
};
