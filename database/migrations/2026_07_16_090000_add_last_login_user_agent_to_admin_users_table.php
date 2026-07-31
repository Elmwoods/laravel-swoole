<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 admin_users 增加最后登录 User-Agent 字段（Admin Security 阶段）。
 *
 * 在既有 last_login_at / last_login_ip 之外补记登录设备的 User-Agent，
 * 便于识别异地/新设备登录，增强后台账号安全审计。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->string('last_login_user_agent', 180)->nullable()->after('last_login_ip'); // 最后登录设备 UA，可空（历史账号未记录）
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            // 回滚 up()：移除最后登录 UA 字段
            $table->dropColumn('last_login_user_agent');
        });
    }
};
