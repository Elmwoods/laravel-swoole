<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('admin_email')->nullable();
            $table->string('type', 20)->index();
            $table->string('trigger', 20)->index();
            $table->string('status', 20)->index();
            $table->json('summary');
            $table->json('checks');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_inspections');
    }
};
