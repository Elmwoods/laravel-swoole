<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运维值班交接记录表 ops_shift_handovers（运维告警——值班交接/Handover）。
 * 记录从上一值班人到下一值班人的交接：交接备注与交接时未关闭的告警数量快照。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_shift_handovers', function (Blueprint $table): void {
            $table->id();
            $table->string('from_assignee', 120)->nullable(); // 交出方值班人标识；可空（首次上岗时无上一班）
            $table->string('to_assignee', 120); // 接手方值班人标识
            $table->string('note', 2000)->nullable(); // 交接备注，可空，最长 2000 字符
            $table->unsignedInteger('open_alert_count')->default(0); // 交接时刻未关闭告警数量的快照，默认 0
            $table->foreignId('created_by')->nullable(); // 记录创建者管理员 ID，可空
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 ops_shift_handovers 整张表
    public function down(): void
    {
        Schema::dropIfExists('ops_shift_handovers');
    }
};
