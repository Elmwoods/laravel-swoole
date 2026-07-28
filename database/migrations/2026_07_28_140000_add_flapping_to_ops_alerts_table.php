<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->unsignedInteger('flap_count')->default(0)->after('hit_count');
            $table->timestamp('flapping_until')->nullable()->after('suppressed_at');
        });
    }

    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn(['flap_count', 'flapping_until']);
        });
    }
};
