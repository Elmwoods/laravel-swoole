<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel 框架基础认证迁移。
 *
 * 建立三张标准表：users（前台用户）、password_reset_tokens（找回密码令牌）、
 * sessions（数据库会话驱动）。这是全新应用脚手架自带的第一张迁移，
 * 为整个用户认证体系奠基。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique(); // 邮箱唯一，作为登录账号
            $table->timestamp('email_verified_at')->nullable(); // 邮箱验证时间，未验证时为 null
            $table->string('password'); // 存储哈希后的密码
            $table->rememberToken(); // “记住我”令牌列
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary(); // 以邮箱作主键，每个邮箱同时只保留一个重置令牌
            $table->string('token'); // 重置令牌（哈希）
            $table->timestamp('created_at')->nullable(); // 令牌签发时间，用于判断过期
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary(); // 会话 ID 作主键
            $table->foreignId('user_id')->nullable()->index(); // 关联用户，未登录会话为 null，建索引便于按用户清理
            $table->string('ip_address', 45)->nullable(); // 长度 45 以兼容 IPv6
            $table->text('user_agent')->nullable();
            $table->longText('payload'); // 序列化后的会话数据
            $table->integer('last_activity')->index(); // 最后活动时间戳，建索引用于清理过期会话
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚 up()：删除认证相关三张表
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
