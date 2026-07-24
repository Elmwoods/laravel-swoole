<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ip_rules', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['allow', 'deny'])->index();
            $table->string('cidr', 64);
            $table->string('label', 120)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['type', 'cidr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ip_rules');
    }
};
