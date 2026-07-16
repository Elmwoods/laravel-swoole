<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->json('value');
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_settings');
    }
};
