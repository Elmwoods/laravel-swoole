<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->timestamp('suppressed_at')->nullable()->after('escalation_level');
        });
    }

    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn('suppressed_at');
        });
    }
};
