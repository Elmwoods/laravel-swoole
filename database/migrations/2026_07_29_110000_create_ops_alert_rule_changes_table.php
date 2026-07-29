<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_alert_rule_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 80)->index();
            $table->string('field', 40);
            $table->string('old_value', 120)->nullable();
            $table->string('new_value', 120)->nullable();
            $table->string('actor', 120)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_alert_rule_changes');
    }
};
