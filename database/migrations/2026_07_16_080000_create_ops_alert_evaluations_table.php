<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 Ops Center 告警评估记录表（告警可配置化阶段）。
 *
 * 每次告警评估（定时或手动触发）留一条运行记录，用于观测评估器本身的健康度：
 * 本轮检测到多少告警、自动恢复多少、耗时多久，便于排查漏报/误报。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger', 20)->index(); // 触发方式：schedule 定时 / manual 手动，建索引便于筛选
            $table->string('status', 20)->index(); // 评估结果状态，建索引便于筛选
            $table->unsignedInteger('detected_count')->default(0); // 本轮检测到的告警数
            $table->unsignedInteger('auto_resolved_count')->default(0); // 本轮自动恢复的告警数
            $table->timestamp('started_at')->nullable()->index(); // 开始时间，建索引便于按时间排序
            $table->timestamp('finished_at')->nullable()->index(); // 结束时间，建索引便于按时间排序
            $table->unsignedInteger('duration_ms')->default(0); // 评估耗时（毫秒）
            $table->string('message', 500)->nullable(); // 备注/异常信息
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除告警评估记录表
        Schema::dropIfExists('ops_alert_evaluations');
    }
};
