<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建后台登录事件表（登录风控 / 可信设备阶段）。
 *
 * 为每次后台登录留一条事件记录，标记来源 IP、UA，以及是否可信设备、
 * 是否首次出现的 IP / UA，用于异常登录检测与安全告警。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index(); // 关联管理员，建索引便于按人查询登录历史（此处未加外键约束）
            $table->string('ip_address', 45)->nullable(); // 登录 IP，长度 45 兼容 IPv6
            $table->string('user_agent', 180)->nullable(); // 登录设备 UA
            $table->boolean('trusted')->default(false); // 是否来自可信设备
            $table->boolean('is_new_ip')->default(false); // 是否首次出现的 IP（风控信号）
            $table->boolean('is_new_user_agent')->default(false); // 是否首次出现的 UA（风控信号）
            $table->timestamp('created_at')->nullable()->index(); // 登录时间，建索引便于按时间排序（仅 created_at，无 updated_at）
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除后台登录事件表
        Schema::dropIfExists('admin_login_events');
    }
};
