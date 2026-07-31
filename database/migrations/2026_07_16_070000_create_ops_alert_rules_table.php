<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建 Ops Center 告警规则表（告警可配置化阶段）。
 *
 * 将原本硬编码的告警阈值抽成可配置规则：每条规则针对某个来源的某个指标，
 * 配置比较操作符与预警/严重双阈值，评估器据此判定是否触发告警。
 * 各列语义见下方 ->comment() 说明。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique()->comment('系统内置规则标识');
            $table->string('name', 120)->comment('规则名称');
            $table->string('source', 50)->index()->comment('告警来源');
            $table->string('metric', 80)->comment('指标标识');
            $table->string('operator', 10)->default('>=')->comment('比较操作符');
            $table->decimal('warning_threshold', 12, 3)->nullable()->comment('预警阈值');
            $table->decimal('critical_threshold', 12, 3)->nullable()->comment('严重阈值');
            $table->string('unit', 20)->nullable()->comment('单位');
            $table->boolean('is_active')->default(true)->index()->comment('是否启用');
            $table->string('description', 500)->nullable()->comment('规则说明');
            $table->unsignedInteger('sort_order')->default(100)->index()->comment('排序');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // 回滚 up()：删除告警规则表
        Schema::dropIfExists('ops_alert_rules');
    }
};
