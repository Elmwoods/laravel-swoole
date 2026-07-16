<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->string('assigned_to', 120)->nullable()->after('acknowledge_note')->index();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn(['assigned_to', 'assigned_at']);
        });
    }
};
