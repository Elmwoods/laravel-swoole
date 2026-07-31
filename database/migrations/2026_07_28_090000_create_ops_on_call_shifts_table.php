<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运维值班排班表 ops_on_call_shifts（运维告警——值班/On-Call 排班）。
 * 记录各时间段的值班人，供告警自动指派/升级时确定当前值班对象。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_on_call_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('assignee', 120);          // 值班人标识（同 assigned_to 自由串）
            $table->string('label', 120)->nullable(); // 排班备注，可空
            $table->timestamp('starts_at')->index(); // 值班开始时间；加索引便于按时间查找当前生效班次
            $table->timestamp('ends_at')->index(); // 值班结束时间；加索引便于按时间查找当前生效班次
            $table->boolean('is_active')->default(true)->index(); // 是否启用，默认启用；加索引便于只取生效排班
            $table->foreignId('created_by')->nullable(); // 创建者管理员 ID，可空
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 ops_on_call_shifts 整张表
    public function down(): void
    {
        Schema::dropIfExists('ops_on_call_shifts');
    }
};
