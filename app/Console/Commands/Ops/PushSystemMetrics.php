<?php

namespace App\Console\Commands\Ops;

use App\Events\Ops\SystemMetricsUpdated;
use App\Services\Ops\MetricsBufferService;
use App\Services\Ops\SystemMetricsCollector;
use Illuminate\Console\Command;

/**
 * 系统监控流（常驻进程）
 *
 * 命令 `ops:metrics:push`（无参数），并非按调度周期触发，而是作为一个常驻
 * 守护进程持续运行：内部 `while (true)` 无限循环，每 3 秒采集一次系统指标，
 * 分别写入 Redis 历史缓冲并通过 WebSocket 广播给前端实时面板。
 *
 * 韧性设计：每轮采集都包裹在 try/catch 中，任何异常仅记录 error 日志而不
 * 中断循环，确保单次采集失败不会导致整个常驻进程崩溃退出。
 */
class PushSystemMetrics extends Command
{
    protected $signature = 'ops:metrics:push';

    protected $description = 'Start system metrics streaming';

    public function handle(): void
    {
        $this->info('System Metrics Stream started...'.time());

        // 常驻循环：进程存活期间持续采集并推送，不主动退出
        while (true) {

            try {

                // 采集当前系统指标快照（CPU/内存/负载等）
                $data = app(SystemMetricsCollector::class)->collect();

                // 1. Redis 历史
                app(MetricsBufferService::class)->push($data);

                // 2. WebSocket 推送
                broadcast(new SystemMetricsUpdated($data));

            } catch (\Throwable $e) {

                // 防止进程挂掉
                // 单轮采集异常只记日志，循环继续，保证守护进程不因偶发错误退出
                logger()->error('Metrics stream error', [
                    'message' => $e->getMessage(),
                ]);
            }

            // 控制频率（关键）
            // 每轮之间休眠，控制采集/推送频率，避免空转占满 CPU
            usleep(3000000); // 3秒
        }
    }
}
