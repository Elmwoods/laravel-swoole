<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('ops_alerts')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->index();
            $table->string('author', 120);
            $table->string('body', 2000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_notes');
    }
};
