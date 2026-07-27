<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alert_silences', function (Blueprint $table): void {
            $table->enum('recurrence', ['once', 'daily', 'weekly'])->default('once')->after('ends_at');
            $table->json('days_of_week')->nullable()->after('recurrence'); // weekly：0=周日…6=周六
            $table->string('start_time', 5)->nullable()->after('days_of_week'); // 'HH:MM'
            $table->string('end_time', 5)->nullable()->after('start_time');
        });
    }

    public function down(): void
    {
        Schema::table('ops_alert_silences', function (Blueprint $table): void {
            $table->dropColumn(['recurrence', 'days_of_week', 'start_time', 'end_time']);
        });
    }
};
