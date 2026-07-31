<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 早期 admin_sessions 建表迁移曾用列名 session_id_hash，后改为 session_token_hash。
 * 已应用旧版本的环境（列仍是 session_id_hash）需此迁移把列改名对齐当前代码；
 * 全新库（建表迁移已是 session_token_hash）则跳过，保持幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 仅当旧列 session_id_hash 存在且新列 session_token_hash 尚不存在时才改名，保证多次运行/新库均幂等
        if (Schema::hasColumn('admin_sessions', 'session_id_hash')
            && ! Schema::hasColumn('admin_sessions', 'session_token_hash')) {
            Schema::table('admin_sessions', function (Blueprint $table): void {
                $table->renameColumn('session_id_hash', 'session_token_hash');
            });
        }
    }

    // down：回滚 up()，把列名改回 session_id_hash（同样带存在性判断以保持幂等）
    public function down(): void
    {
        if (Schema::hasColumn('admin_sessions', 'session_token_hash')
            && ! Schema::hasColumn('admin_sessions', 'session_id_hash')) {
            Schema::table('admin_sessions', function (Blueprint $table): void {
                $table->renameColumn('session_token_hash', 'session_id_hash');
            });
        }
    }
};
