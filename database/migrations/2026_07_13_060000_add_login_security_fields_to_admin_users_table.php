<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 admin_users 增加登录安全字段（Admin Security 阶段）。
 *
 * 新增 password_changed_at 与 session_version：前者记录密码最后修改时间，
 * 后者用于会话版本控制——改密后自增此值即可让旧会话全部失效（强制下线）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->timestamp('password_changed_at')->nullable()->after('password'); // 密码最后修改时间，可空（历史账号未记录）
            $table->unsignedInteger('session_version')->default(1)->after('password_changed_at'); // 会话版本号，改密后自增使旧会话失效
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            // 回滚 up()：移除新增的两个安全字段
            $table->dropColumn(['password_changed_at', 'session_version']);
        });
    }
};
