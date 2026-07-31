<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为运维告警表 ops_alerts 增加「升级时间」列（运维告警——告警升级功能）。
 * 记录告警被升级（escalate）的时刻，供升级流程与展示使用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->timestamp('escalated_at')->nullable()->after('assigned_at'); // 升级时间；为空表示尚未升级，故可空。放在 assigned_at 之后
        });
    }

    // down：回滚 up()，从 ops_alerts 删除 escalated_at 列
    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn('escalated_at');
        });
    }
};
