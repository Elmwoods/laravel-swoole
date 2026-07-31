<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 admin_users 增加 2FA 防重放字段（后台 2FA 加固阶段）。
 *
 * 新增 two_factor_last_used_step，记录上一次成功校验所用的 TOTP 时间步（time step）。
 * 校验时若当前步 <= 已记录步则拒绝，从而防止同一 30 秒验证码被重复使用（防重放攻击）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            // 上次成功验证使用的 TOTP 时间步；用 unsignedBigInteger 容纳 Unix 时间/30 的大整数，可空（从未验证过）
            $table->unsignedBigInteger('two_factor_last_used_step')
                ->nullable()
                ->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            // 回滚 up()：移除 2FA 防重放字段
            $table->dropColumn('two_factor_last_used_step');
        });
    }
};
