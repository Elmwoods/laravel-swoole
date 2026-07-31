<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为值班排班表 ops_on_call_shifts 增加「周期重复」相关列。
 * 支持每日（daily）/每周（weekly）循环排班，用每日时间段 + 星期集合表达重复班次。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_on_call_shifts', function (Blueprint $table): void {
            $table->enum('recurrence', ['once', 'daily', 'weekly'])->default('once')->after('ends_at'); // 重复方式枚举：once=一次性/daily=每日/weekly=每周；默认 once
            $table->json('days_of_week')->nullable()->after('recurrence'); // 0=Sun..6=Sat
            $table->string('start_time', 5)->nullable()->after('days_of_week'); // 'HH:MM'
            $table->string('end_time', 5)->nullable()->after('start_time'); // 每日重复时段的结束时间 'HH:MM'，可空（once 时不用）
        });
    }

    // down：回滚 up()，从 ops_on_call_shifts 删除 recurrence/days_of_week/start_time/end_time 四列
    public function down(): void
    {
        Schema::table('ops_on_call_shifts', function (Blueprint $table): void {
            $table->dropColumn(['recurrence', 'days_of_week', 'start_time', 'end_time']);
        });
    }
};
