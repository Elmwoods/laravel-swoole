<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 系统指标分钟级采样表，用于多天历史趋势（实时仍走 Redis/WebSocket）。
     */
    public function up(): void
    {
        Schema::create('ops_metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->decimal('cpu_load', 8, 2)->default(0)->comment('1 分钟负载');
            $table->decimal('load1', 8, 2)->default(0)->comment('load average 1min');
            $table->decimal('memory_used_percent', 5, 2)->default(0)->comment('内存使用率 %');
            $table->decimal('swap_used_percent', 5, 2)->default(0)->comment('swap 使用率 %');
            $table->timestamp('captured_at')->index()->comment('采样时间');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_metric_samples');
    }
};
