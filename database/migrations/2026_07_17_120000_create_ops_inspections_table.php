<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 Ops Center 巡检记录表（系统巡检阶段）。
 *
 * 记录系统巡检（inspection）的每次运行：巡检类型、触发方式、总体状态、
 * 各检查项明细与摘要、耗时等。与 ops_release_checks 类似，但面向日常/定时巡检，
 * 多出 type 与 trigger 两个维度以区分巡检种类与触发来源。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete(); // 触发人，删除管理员时置空以保留历史
            $table->string('admin_email')->nullable(); // 冗余邮箱，管理员被删后仍可追溯
            $table->string('type', 20)->index(); // 巡检类型，建索引便于筛选
            $table->string('trigger', 20)->index(); // 触发方式：schedule 定时 / manual 手动，建索引便于筛选
            $table->string('status', 20)->index(); // 总体状态，建索引便于筛选
            $table->json('summary'); // 结果摘要（JSON）
            $table->json('checks'); // 各检查项明细（JSON）
            $table->unsignedInteger('duration_ms')->default(0); // 巡检耗时（毫秒）
            $table->timestamp('started_at')->nullable()->index(); // 开始时间，建索引便于按时间排序
            $table->timestamp('finished_at')->nullable()->index(); // 结束时间，建索引便于按时间排序
            $table->string('failure_message', 500)->nullable(); // 失败时的错误信息
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除巡检记录表
        Schema::dropIfExists('ops_inspections');
    }
};
