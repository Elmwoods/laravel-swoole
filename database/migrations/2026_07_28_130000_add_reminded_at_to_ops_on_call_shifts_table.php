<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为值班排班表 ops_on_call_shifts 增加「已提醒时间」列（运维告警——值班上岗提醒）。
 * 记录该班次的上岗提醒已发送的时刻，避免对同一班次重复提醒。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_on_call_shifts', function (Blueprint $table): void {
            $table->timestamp('reminded_at')->nullable()->after('is_active'); // 提醒已发送时间；为空表示尚未提醒，故可空
        });
    }

    // down：回滚 up()，从 ops_on_call_shifts 删除 reminded_at 列
    public function down(): void
    {
        Schema::table('ops_on_call_shifts', function (Blueprint $table): void {
            $table->dropColumn('reminded_at');
        });
    }
};
