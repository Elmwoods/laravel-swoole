<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 后台管理安全体系基础迁移（Admin Security 阶段）。
 *
 * 建立后台管理员的 RBAC 权限体系：admin_users（管理员）、admin_roles（角色）、
 * admin_permissions（权限）、两张多对多关联表，以及 admin_audit_logs（操作审计日志）。
 * 与前台 users 表分离，独立管理后台账号、角色授权与操作留痕。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique(); // 邮箱唯一，作为后台登录账号
            $table->string('password'); // 哈希后的密码
            $table->boolean('is_active')->default(true); // 是否启用，默认启用；停用即禁止登录
            $table->timestamp('last_login_at')->nullable(); // 最后登录时间
            $table->string('last_login_ip', 45)->nullable(); // 最后登录 IP，长度 45 兼容 IPv6
            $table->timestamps();
        });

        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // 角色唯一标识，代码中按 slug 引用
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true); // 是否启用
            $table->boolean('is_system')->default(false); // 是否系统内置角色，内置角色通常禁止删除
            $table->timestamps();
        });

        Schema::create('admin_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // 权限唯一标识
            $table->string('group')->index(); // 权限分组，建索引便于按模块归类展示
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // 管理员-角色 多对多关联表
        Schema::create('admin_role_admin_user', function (Blueprint $table): void {
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete(); // 外键，管理员删除时级联清除授权
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete(); // 外键，角色删除时级联清除授权
            $table->primary(['admin_user_id', 'admin_role_id']); // 复合主键防止重复授权
        });

        // 角色-权限 多对多关联表
        Schema::create('admin_permission_admin_role', function (Blueprint $table): void {
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete(); // 外键，角色删除时级联清除
            $table->foreignId('admin_permission_id')->constrained('admin_permissions')->cascadeOnDelete(); // 外键，权限删除时级联清除
            $table->primary(['admin_role_id', 'admin_permission_id']); // 复合主键防止重复分配
        });

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete(); // 关联管理员，删除管理员时置空以保留历史日志
            $table->string('admin_email')->nullable(); // 冗余保存邮箱，管理员被删后仍可追溯是谁
            $table->string('module')->index(); // 操作模块，建索引便于筛选
            $table->string('action')->index(); // 操作动作，建索引便于筛选
            $table->string('result', 20)->index(); // 结果（成功/失败等），建索引便于筛选
            $table->unsignedSmallInteger('status_code')->nullable(); // HTTP 状态码
            $table->string('target_type')->nullable(); // 操作目标类型（多态）
            $table->string('target_id')->nullable(); // 操作目标 ID（多态）
            $table->json('payload')->nullable(); // 请求上下文快照（JSON）
            $table->string('ip_address', 45)->nullable(); // 来源 IP，长度 45 兼容 IPv6
            $table->text('user_agent')->nullable();
            $table->string('message')->nullable(); // 补充描述
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：按外键依赖倒序删除（先删关联/日志表，再删主表）
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('admin_permission_admin_role');
        Schema::dropIfExists('admin_role_admin_user');
        Schema::dropIfExists('admin_permissions');
        Schema::dropIfExists('admin_roles');
        Schema::dropIfExists('admin_users');
    }
};
