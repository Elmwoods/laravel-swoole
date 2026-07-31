<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为运维告警表 ops_alerts 增加「标签」列（运维告警——标签分类/过滤）。
 * 用一组自由标签给告警打标，便于分组、检索与看板过滤。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('context'); // 标签数组，用 JSON 存储一组字符串标签；可空表示无标签
        });
    }

    // down：回滚 up()，从 ops_alerts 删除 tags 列
    public function down(): void
    {
        Schema::table('ops_alerts', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
    }
};
