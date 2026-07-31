<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运维告警通知渠道健康表 ops_channel_health（运维告警——通知渠道健康监测）。
 * 记录各告警发送渠道（如邮件、Webhook、IM 等）的最近发送状态与连续失败情况，
 * 用于探测渠道是否失联并在界面上展示健康度。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_channel_health', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 40)->unique(); // 渠道标识；唯一约束保证每个渠道只有一条健康记录
            $table->string('status', 20)->default('unknown'); // healthy / failing / unknown
            $table->unsignedInteger('consecutive_failures')->default(0); // 连续失败次数；成功后清零，用于判定 failing
            $table->timestamp('last_ok_at')->nullable(); // 最近一次成功发送时间，可空（从未成功时为空）
            $table->timestamp('last_checked_at')->nullable()->index(); // 最近一次检测时间；加索引便于按检测时间排序/清理
            $table->string('last_error', 300)->nullable(); // 最近一次失败的错误信息，可空
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 ops_channel_health 整张表
    public function down(): void
    {
        Schema::dropIfExists('ops_channel_health');
    }
};
