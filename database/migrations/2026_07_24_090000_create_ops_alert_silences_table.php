<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_silences', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->json('sources')->nullable();     // 空/null = 匹配全部来源
            $table->json('severities')->nullable();  // 空/null = 匹配全部严重级
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_silences');
    }
};
