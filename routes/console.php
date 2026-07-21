<?php

// Ops Center 定时任务调度入口。
use App\Jobs\Docker\CollectDockerLogsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/**
 * Ops - Scheduler Layer
 * 所有采集任务统一在这里调度
 */

/**
 * Docker logs 推送
 */
Schedule::job(new CollectDockerLogsJob())
    ->everyTenSeconds()
    ->withoutOverlapping();

/**
 * Ops 告警规则评估。
 *
 * 评估结果会写入数据库，并通过 WebSocket 推送轻量告警摘要。
 */
Schedule::command('ops:alerts:evaluate')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('ops:logs:watch-errors --once')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('ops:inspections:run --type=light')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/**
 * 每日清理过期巡检历史，避免 ops_inspections 无限增长。
 */
Schedule::command('ops:inspections:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping();
