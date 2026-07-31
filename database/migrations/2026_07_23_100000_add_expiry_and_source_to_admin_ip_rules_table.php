<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 IP 访问规则表 admin_ip_rules 增加「过期时间」与「来源」两列。
 * 支持临时规则（到期自动失效）以及区分手动配置与系统自动生成（如自动封禁）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ip_rules', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->index()->after('is_active'); // 过期时间；为空表示永久有效。加索引便于扫描到期规则做清理
            $table->enum('source', ['manual', 'auto'])->default('manual')->after('expires_at'); // 来源枚举：manual=人工添加/auto=系统自动生成；默认 manual
        });
    }

    // down：回滚 up()，从 admin_ip_rules 删除 expires_at 与 source 两列
    public function down(): void
    {
        Schema::table('admin_ip_rules', function (Blueprint $table): void {
            $table->dropColumn(['expires_at', 'source']);
        });
    }
};
