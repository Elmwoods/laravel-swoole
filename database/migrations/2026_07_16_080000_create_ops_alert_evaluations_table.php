<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger', 20)->index();
            $table->string('status', 20)->index();
            $table->unsignedInteger('detected_count')->default(0);
            $table->unsignedInteger('auto_resolved_count')->default(0);
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_evaluations');
    }
};
