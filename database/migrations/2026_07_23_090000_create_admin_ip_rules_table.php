<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 后台 IP 访问规则表 admin_ip_rules（管理端安全阶段——IP 白/黑名单）。
 * 以 CIDR 网段配置允许（allow）或拒绝（deny）访问管理后台的来源 IP。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ip_rules', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['allow', 'deny'])->index(); // 规则类型枚举：allow=白名单/deny=黑名单；加索引便于按类型筛选
            $table->string('cidr', 64); // CIDR 网段字符串（如 10.0.0.0/8）；长度 64 兼容 IPv6
            $table->string('label', 120)->nullable(); // 规则备注说明，可空
            $table->boolean('is_active')->default(true)->index(); // 是否启用，默认启用；加索引便于只取生效规则
            $table->foreignId('created_by')->nullable(); // 创建者管理员 ID，可空（系统/自动生成时无归属）
            $table->timestamps();

            $table->unique(['type', 'cidr']); // 复合唯一：同一类型下同一网段不可重复配置
        });
    }

    // down：回滚 up()，删除 admin_ip_rules 整张表
    public function down(): void
    {
        Schema::dropIfExists('admin_ip_rules');
    }
};
