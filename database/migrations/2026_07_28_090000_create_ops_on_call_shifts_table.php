<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_on_call_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('assignee', 120);          // 值班人标识（同 assigned_to 自由串）
            $table->string('label', 120)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_on_call_shifts');
    }
};
