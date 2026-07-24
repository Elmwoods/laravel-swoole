<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_channel_health', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 40)->unique();
            $table->string('status', 20)->default('unknown'); // healthy / failing / unknown
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_channel_health');
    }
};
