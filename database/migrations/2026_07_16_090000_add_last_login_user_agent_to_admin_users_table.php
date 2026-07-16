<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->string('last_login_user_agent', 180)->nullable()->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn('last_login_user_agent');
        });
    }
};
