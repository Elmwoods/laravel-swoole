<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 后台安全设置表 admin_security_settings（管理端安全阶段）。
 * 以 key/value 形式集中存储各类安全相关配置项（如登录策略、IP 校验开关等）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_security_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique(); // 配置项键名；唯一约束保证每个设置项只有一条记录
            $table->json('value'); // 配置值，用 JSON 存储以支持任意结构（布尔/数组/对象等）
            $table->string('description', 300)->nullable(); // 配置项说明文案，可空
            $table->timestamps();
        });
    }

    // down：回滚 up()，删除 admin_security_settings 整张表
    public function down(): void
    {
        Schema::dropIfExists('admin_security_settings');
    }
};
