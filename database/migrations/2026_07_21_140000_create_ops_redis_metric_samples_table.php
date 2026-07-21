<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redis 指标分钟级采样表，用于多天历史趋势（实时仍走 Cache 轮询图）。
     */
    public function up(): void
    {
        Schema::create('ops_redis_metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('ops')->default(0)->comment('instantaneous_ops_per_sec');
            $table->unsignedInteger('clients')->default(0)->comment('connected_clients');
            $table->decimal('memory_mb', 10, 2)->default(0)->comment('used_memory (MB)');
            $table->decimal('hit_rate', 5, 2)->default(0)->comment('keyspace 命中率 %');
            $table->timestamp('captured_at')->index()->comment('采样时间');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_redis_metric_samples');
    }
};
