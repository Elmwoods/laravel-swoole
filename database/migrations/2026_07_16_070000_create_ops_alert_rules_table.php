<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        Schema::dropIfExists('ops_alert_rules');
    }
};
