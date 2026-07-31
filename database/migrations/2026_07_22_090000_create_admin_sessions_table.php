<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 后台管理会话表 admin_sessions（管理端安全阶段）。
 * 记录每个管理员登录后的活跃会话，用于会话列表展示与远程强制下线（撤销）。
 * 会话凭证只存哈希值，不落明文；支持按管理员维度管理多个设备/会话。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index(); // 所属管理员；加索引便于按人查询其全部会话
            $table->string('session_token_hash', 64)->unique(); // 会话令牌的哈希（非明文）；唯一约束保证一个令牌只对应一条会话
            $table->string('label', 180)->nullable(); // 可选的会话备注/设备名，可空
            $table->string('ip_address', 45)->nullable(); // 登录来源 IP，长度 45 兼容 IPv6，可空
            $table->string('user_agent', 180)->nullable(); // 客户端 UA 摘要，可空
            $table->timestamp('last_activity_at')->nullable()->index(); // 最后活跃时间；加索引便于筛选空闲/过期会话
            $table->timestamp('revoked_at')->nullable(); // 撤销时间；非空即表示会话已被强制下线，为空表示仍有效
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 admin_sessions 整张表
    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
    }
};
