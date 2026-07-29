<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_shift_handovers', function (Blueprint $table): void {
            $table->id();
            $table->string('from_assignee', 120)->nullable();
            $table->string('to_assignee', 120);
            $table->string('note', 2000)->nullable();
            $table->unsignedInteger('open_alert_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_shift_handovers');
    }
};
