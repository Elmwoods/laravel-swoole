<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('ops_alerts')->cascadeOnDelete();
            $table->string('action', 40)->index();
            $table->string('actor', 120)->nullable()->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_events');
    }
};
