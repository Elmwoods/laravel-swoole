<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建 Ops Center 告警记录表。
     *
     * 告警中心需要保留历史状态，不能只依赖内存缓存；Octane Worker 重启后，
     * 运维人员仍然可以看到未确认告警和最近处理记录。
     */
    public function up(): void
    {
        Schema::create('ops_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 120)->unique()->comment('同类告警指纹，用于去重');
            $table->string('source', 50)->index()->comment('告警来源，例如 disk、queue、docker');
            $table->string('severity', 20)->index()->comment('级别：critical、warning、info');
            $table->string('title', 160)->comment('告警标题');
            $table->text('message')->comment('告警说明');
            $table->json('context')->nullable()->comment('轻量上下文，禁止存储大日志正文');
            $table->string('status', 20)->default('open')->index()->comment('状态：open、acknowledged、resolved');
            $table->unsignedInteger('hit_count')->default(1)->comment('重复命中次数');
            $table->timestamp('last_seen_at')->nullable()->index()->comment('最后命中时间');
            $table->timestamp('acknowledged_at')->nullable()->comment('确认时间');
            $table->string('acknowledged_by', 120)->nullable()->comment('确认人');
            $table->string('acknowledge_note', 500)->nullable()->comment('确认备注');
            $table->timestamps();
        });
    }

    /**
     * 回滚告警记录表。
     */
    public function down(): void
    {
        Schema::dropIfExists('ops_alerts');
    }
};
