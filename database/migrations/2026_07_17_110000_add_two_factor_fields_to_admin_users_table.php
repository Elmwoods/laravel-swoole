<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 admin_users 增加两步验证（2FA/TOTP）字段（后台 2FA 阶段）。
 *
 * 新增 TOTP 密钥、确认时间与恢复码三列，为后台管理员启用基于时间的一次性密码
 * 双因素认证。密钥与恢复码在应用层加密后存储。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('last_login_user_agent'); // TOTP 密钥（加密后），未启用 2FA 时为 null
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret'); // 2FA 确认启用时间，null 表示尚未完成绑定
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at'); // 一次性恢复码列表（加密后的 JSON）
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            // 回滚 up()：移除三个 2FA 字段
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_recovery_codes',
            ]);
        });
    }
};
