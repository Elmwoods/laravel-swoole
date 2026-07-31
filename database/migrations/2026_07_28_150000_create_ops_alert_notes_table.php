<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运维告警备注表 ops_alert_notes（运维告警——处理备注/协作记录）。
 * 让处理人在具体告警下追加文字备注，形成处理时间线。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('ops_alerts')->cascadeOnDelete(); // 外键指向 ops_alerts；级联删除：告警被删时其备注一并删除
            $table->foreignId('admin_user_id')->nullable()->index(); // 备注作者的管理员 ID，可空（外部/系统备注无归属）；加索引便于按人查询
            $table->string('author', 120); // 作者显示名（冗余保存，便于即使账号删除仍可展示）
            $table->string('body', 2000); // 备注正文，最长 2000 字符
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 ops_alert_notes 整张表
    public function down(): void
    {
        Schema::dropIfExists('ops_alert_notes');
    }
};
