<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 后台审计日志「筛选预设」表 admin_audit_presets（管理端安全阶段）。
 * 让管理员把常用的审计日志过滤条件保存为具名预设，方便一键复用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index(); // 预设归属的管理员；加索引便于按人加载其全部预设
            $table->string('name', 80); // 预设名称（同一管理员下唯一，见下方复合唯一约束）
            $table->json('filters'); // 该预设保存的过滤条件（键值对），用 JSON 存储以适应任意筛选字段
            $table->timestamps();

            $table->unique(['admin_user_id', 'name']); // 复合唯一：同一管理员下预设名不可重复（不同管理员可重名）
        });
    }

    // down：回滚 up()，删除 admin_audit_presets 整张表
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_presets');
    }
};
