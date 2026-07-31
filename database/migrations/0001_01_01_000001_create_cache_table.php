<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel 框架数据库缓存迁移。
 *
 * 建立 cache 与 cache_locks 两张表，供 database 缓存驱动与原子锁使用。
 * 当未配置 Redis/Memcached 时，缓存与锁可回退到数据库。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary(); // 缓存键作主键
            $table->mediumText('value'); // 序列化后的缓存值
            $table->bigInteger('expiration')->index(); // 过期时间戳，建索引便于清理过期项
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary(); // 锁名作主键
            $table->string('owner'); // 持锁者标识，用于安全释放
            $table->bigInteger('expiration')->index(); // 锁过期时间戳，防止死锁
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚 up()：删除缓存与锁两张表
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
