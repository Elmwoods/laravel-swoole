<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运维告警静默规则表 ops_alert_silences（运维告警——告警静默/免打扰）。
 * 在指定时间窗内，按来源/严重级匹配的告警不再发送通知，用于维护窗口或抑制噪声。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_silences', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120)->nullable(); // 静默规则备注，可空
            $table->timestamp('starts_at')->index(); // 静默生效起始时间；加索引便于按时间窗匹配
            $table->timestamp('ends_at')->index(); // 静默结束时间；加索引便于按时间窗匹配
            $table->json('sources')->nullable();     // 空/null = 匹配全部来源
            $table->json('severities')->nullable();  // 空/null = 匹配全部严重级
            $table->boolean('is_active')->default(true)->index(); // 是否启用，默认启用；加索引便于只取生效规则
            $table->foreignId('created_by')->nullable(); // 创建者管理员 ID，可空
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 ops_alert_silences 整张表
    public function down(): void
    {
        Schema::dropIfExists('ops_alert_silences');
    }
};
