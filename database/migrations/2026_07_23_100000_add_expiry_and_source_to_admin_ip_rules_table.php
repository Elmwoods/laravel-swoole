<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_ip_rules', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->index()->after('is_active');
            $table->enum('source', ['manual', 'auto'])->default('manual')->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_ip_rules', function (Blueprint $table): void {
            $table->dropColumn(['expires_at', 'source']);
        });
    }
};
