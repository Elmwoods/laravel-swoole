<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为运维告警表 ops_alerts 增加「升级级别」列（运维告警——多级告警升级）。
 * 记录告警当前处于第几级升级，支持逐级升级到不同联系人/渠道。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('escalated_at'); // 升级级别，默认 0（未升级）；用无符号 TinyInteger 因级别数很小
        });
    }

    // down：回滚 up()，从 ops_alerts 删除 escalation_level 列
    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn('escalation_level');
        });
    }
};
