<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 180)->nullable();
            $table->boolean('trusted')->default(false);
            $table->boolean('is_new_ip')->default(false);
            $table->boolean('is_new_user_agent')->default(false);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_events');
    }
};
