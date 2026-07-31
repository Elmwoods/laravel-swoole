<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 ops_alerts 增加“指派”字段（告警协作阶段）。
 *
 * 新增 assigned_to 与 assigned_at，支持把告警指派给具体处理人并记录指派时间，
 * 让告警可在运维团队内分派跟进。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->string('assigned_to', 120)->nullable()->after('acknowledge_note')->index(); // 被指派处理人，可空（未指派），建索引便于按人筛选
            $table->timestamp('assigned_at')->nullable()->after('assigned_to'); // 指派时间
        });
    }

    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            // 回滚 up()：移除指派相关字段
            $table->dropColumn(['assigned_to', 'assigned_at']);
        });
    }
};
