<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运维告警规则变更历史表 ops_alert_rule_changes（运维告警——规则变更审计/历史）。
 * 以「字段级」记录每次告警规则被修改的旧值、新值、操作人与时间，形成可追溯的变更日志。
 * 注意：仅有 created_at（append-only 追加日志），无 updated_at。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_rule_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 80)->index(); // 被变更规则的标识键；加索引便于按规则聚合其变更历史
            $table->string('field', 40); // 发生变更的字段名
            $table->string('old_value', 120)->nullable(); // 变更前的值；可空（新增字段时无旧值）
            $table->string('new_value', 120)->nullable(); // 变更后的值；可空（清空字段时无新值）
            $table->string('actor', 120)->nullable(); // 操作人标识，可空（系统自动变更时无归属）
            $table->timestamp('created_at')->nullable()->index(); // 变更发生时间；加索引便于按时间排序。此处手动声明 created_at（无 timestamps()）
        });
    }

    // down：回滚 up()，删除 ops_alert_rule_changes 整张表
    public function down(): void
    {
        Schema::dropIfExists('ops_alert_rule_changes');
    }
};
