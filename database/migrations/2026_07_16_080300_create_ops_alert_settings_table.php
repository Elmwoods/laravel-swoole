<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 Ops Center 告警设置表（告警协作阶段）。
 *
 * 通用的键值配置表：以 key 为唯一键、value 存 JSON，用于保存告警子系统的
 * 各类可调参数（如通知渠道、静默窗口、去重策略等），无需为每个配置单独建表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique(); // 配置键，唯一约束保证同一 key 只有一条
            $table->json('value'); // 配置值，用 JSON 兼容标量/数组/对象等结构
            $table->string('description', 300)->nullable(); // 配置说明
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除告警设置表
        Schema::dropIfExists('ops_alert_settings');
    }
};
