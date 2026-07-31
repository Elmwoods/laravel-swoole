<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 Ops Center 告警事件流水表（告警协作阶段）。
 *
 * 记录单条告警的完整生命周期动作：确认、指派、状态流转、备注等，
 * 为告警详情页提供时间线（谁在何时做了什么、状态从何变到何）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('ops_alerts')->cascadeOnDelete(); // 外键关联告警，告警删除时级联删除其事件流水
            $table->string('action', 40)->index(); // 动作类型（确认/指派/解决等），建索引便于筛选
            $table->string('actor', 120)->nullable()->index(); // 操作人，可空（系统自动动作），建索引便于按人筛选
            $table->string('from_status', 20)->nullable(); // 变更前状态，创建类动作可空
            $table->string('to_status', 20)->nullable(); // 变更后状态，非状态流转动作可空
            $table->string('note', 500)->nullable(); // 备注
            $table->json('metadata')->nullable(); // 附加上下文（JSON）
            $table->timestamp('created_at')->nullable()->index(); // 发生时间，建索引便于按时间线排序（仅 created_at，无 updated_at）
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除告警事件流水表
        Schema::dropIfExists('ops_alert_events');
    }
};
