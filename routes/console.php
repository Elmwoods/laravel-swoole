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
Schedule::job(new CollectDockerLogsJob)
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
 * 每分钟持久化一个系统指标点，供多天趋势。
 */
Schedule::command('ops:metrics:persist')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * 每分钟持久化一个 Redis 指标点，供多天趋势。
 */
Schedule::command('ops:redis-metrics:persist')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * 每日清理过期巡检历史，避免 ops_inspections 无限增长。
 */
Schedule::command('ops:inspections:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping();

/**
 * 每日清理过期告警评估历史，避免 ops_alert_evaluations 无限增长。
 */
Schedule::command('ops:alerts:prune-evaluations')
    ->dailyAt('03:20')
    ->withoutOverlapping();

/**
 * 每日清理过期系统指标采样，避免 ops_metric_samples 无限增长。
 */
Schedule::command('ops:metrics:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/**
 * 每日清理过期 Redis 指标采样。
 */
Schedule::command('ops:redis-metrics:prune')
    ->dailyAt('03:40')
    ->withoutOverlapping();

/**
 * 每日推送告警聚合摘要（默认 opt-in，config 关闭时命令内 no-op）。
 */
Schedule::command('ops:alerts:digest')
    ->dailyAt('08:00')
    ->withoutOverlapping();

/**
 * 每日清理陈旧/已撤销的管理员会话注册表行。
 */
Schedule::command('admin:sessions:prune')
    ->dailyAt('03:50')
    ->withoutOverlapping();

/**
 * 每 5 分钟扫描审计日志异常（失败登录暴增 / 敏感操作），命中升告警。
 */
Schedule::command('ops:audit:scan-anomalies')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/**
 * 每 5 分钟升级长期未确认的 critical 告警（重推），避免关键告警被遗漏。
 */
Schedule::command('ops:alerts:escalate')
    ->everyFiveMinutes()
    ->withoutOverlapping();
