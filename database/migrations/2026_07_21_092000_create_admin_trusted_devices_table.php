<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('label', 180)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('last_user_agent', 180)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_trusted_devices');
    }
};
