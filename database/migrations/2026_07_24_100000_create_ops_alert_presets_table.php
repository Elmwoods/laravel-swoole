<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();

            $table->unique(['admin_user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_presets');
    }
};
