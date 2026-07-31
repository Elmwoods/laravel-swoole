<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为告警静默规则表 ops_alert_silences 增加「周期重复」相关列。
 * 支持一次性（once）之外的每日（daily）/每周（weekly）循环静默，
 * 用每日时间段 + 星期集合表达重复的免打扰窗口。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alert_silences', function (Blueprint $table): void {
            $table->enum('recurrence', ['once', 'daily', 'weekly'])->default('once')->after('ends_at'); // 重复方式枚举：once=一次性/daily=每日/weekly=每周；默认 once
            $table->json('days_of_week')->nullable()->after('recurrence'); // weekly：0=周日…6=周六
            $table->string('start_time', 5)->nullable()->after('days_of_week'); // 'HH:MM'
            $table->string('end_time', 5)->nullable()->after('start_time'); // 每日重复时段的结束时间 'HH:MM'，可空（once 时不用）
        });
    }

    // down：回滚 up()，从 ops_alert_silences 删除 recurrence/days_of_week/start_time/end_time 四列
    public function down(): void
    {
        Schema::table('ops_alert_silences', function (Blueprint $table): void {
            $table->dropColumn(['recurrence', 'days_of_week', 'start_time', 'end_time']);
        });
    }
};
