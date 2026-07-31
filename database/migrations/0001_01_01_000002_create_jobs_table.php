<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel 框架队列迁移。
 *
 * 建立 jobs（待处理任务）、job_batches（批处理任务）、failed_jobs（失败任务）三张表，
 * 供 database 队列驱动使用，支撑异步任务的入队、批处理与失败重试。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index(); // 队列名，建索引供 worker 按队列拉取
            $table->longText('payload'); // 序列化后的任务内容
            $table->unsignedSmallInteger('attempts'); // 已尝试次数，用于重试上限判断
            $table->unsignedInteger('reserved_at')->nullable(); // 被 worker 领取的时间戳，null 表示空闲可领
            $table->unsignedInteger('available_at'); // 可执行时间戳，支持延迟任务
            $table->unsignedInteger('created_at'); // 入队时间戳
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary(); // 批次 ID 作主键
            $table->string('name');
            $table->integer('total_jobs'); // 批次内任务总数
            $table->integer('pending_jobs'); // 尚未完成的任务数
            $table->integer('failed_jobs'); // 失败任务数
            $table->longText('failed_job_ids'); // 失败任务 ID 列表（JSON 字符串）
            $table->mediumText('options')->nullable(); // 批次回调等选项（序列化）
            $table->integer('cancelled_at')->nullable(); // 取消时间戳，null 表示未取消
            $table->integer('created_at');
            $table->integer('finished_at')->nullable(); // 完成时间戳，null 表示进行中
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique(); // 任务唯一标识，唯一约束防重复记录
            $table->string('connection'); // 队列连接名
            $table->string('queue'); // 队列名
            $table->longText('payload'); // 失败时的任务内容
            $table->longText('exception'); // 异常堆栈信息
            $table->timestamp('failed_at')->useCurrent(); // 失败时间，默认当前时间

            $table->index(['connection', 'queue', 'failed_at']); // 复合索引便于按连接/队列/时间检索失败任务
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚 up()：删除队列相关三张表
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
