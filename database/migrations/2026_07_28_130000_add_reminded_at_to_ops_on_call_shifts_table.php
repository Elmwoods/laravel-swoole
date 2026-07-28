<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_on_call_shifts', function (Blueprint $table): void {
            $table->timestamp('reminded_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('ops_on_call_shifts', function (Blueprint $table): void {
            $table->dropColumn('reminded_at');
        });
    }
};
