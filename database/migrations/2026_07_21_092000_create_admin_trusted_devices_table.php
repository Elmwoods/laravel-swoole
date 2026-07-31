<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建后台可信设备表（登录风控 / 可信设备阶段）。
 *
 * 保存管理员“记住此设备”后签发的可信设备令牌（仅存哈希），在有效期内可跳过 2FA。
 * 配合 admin_login_events 实现基于设备的登录信任。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index(); // 关联管理员，建索引便于按人查询设备（此处未加外键约束）
            $table->string('token_hash', 64)->unique(); // 设备令牌的哈希（仅存哈希不存明文），唯一约束保证一令牌一记录
            $table->string('label', 180)->nullable(); // 设备备注名，便于用户识别
            $table->string('last_ip', 45)->nullable(); // 最近使用 IP，长度 45 兼容 IPv6
            $table->string('last_user_agent', 180)->nullable(); // 最近使用设备 UA
            $table->timestamp('expires_at')->index(); // 过期时间，建索引便于清理过期设备
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除可信设备表
        Schema::dropIfExists('admin_trusted_devices');
    }
};
