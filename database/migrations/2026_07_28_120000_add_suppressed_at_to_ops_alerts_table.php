<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为运维告警表 ops_alerts 增加「抑制时间」列（运维告警——告警静默/抑制）。
 * 记录告警因命中静默规则等原因被抑制（不再通知）的时刻。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->timestamp('suppressed_at')->nullable()->after('escalation_level'); // 被抑制的时间；为空表示未被抑制，故可空
        });
    }

    // down：回滚 up()，从 ops_alerts 删除 suppressed_at 列
    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn('suppressed_at');
        });
    }
};
