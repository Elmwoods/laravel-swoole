<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 Ops Center 发布前检查记录表（发布校验阶段）。
 *
 * 记录每次“发布前置检查”（release check）的运行结果：由哪位管理员触发、
 * 总体状态、各检查项明细与摘要、耗时等，用于上线前的门禁与追溯。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_release_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete(); // 触发人，删除管理员时置空以保留历史
            $table->string('admin_email')->nullable(); // 冗余邮箱，管理员被删后仍可追溯
            $table->string('status', 20)->index(); // 总体状态（通过/失败等），建索引便于筛选
            $table->json('summary'); // 结果摘要（JSON）
            $table->json('checks'); // 各检查项明细（JSON）
            $table->unsignedInteger('duration_ms')->default(0); // 检查耗时（毫秒）
            $table->timestamp('started_at')->nullable()->index(); // 开始时间，建索引便于按时间排序
            $table->timestamp('finished_at')->nullable()->index(); // 结束时间，建索引便于按时间排序
            $table->string('error_message', 500)->nullable(); // 失败时的错误信息
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除发布前检查记录表
        Schema::dropIfExists('ops_release_checks');
    }
};
