<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('last_login_user_agent');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_recovery_codes',
            ]);
        });
    }
};
