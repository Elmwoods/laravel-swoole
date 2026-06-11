<?php

namespace App\Console\Commands\Ops;

use App\Events\Ops\SystemMetricsUpdated;
use App\Services\Ops\MetricsBufferService;
use App\Services\Ops\SystemMetricsCollector;
use Illuminate\Console\Command;

/**
 * 系统监控流（常驻进程）
 */
class PushSystemMetrics extends Command
{
    protected $signature = 'ops:metrics:push';

    protected $description = 'Start system metrics streaming';

    public function handle(): void
    {
        $this->info('System Metrics Stream started...'. time());

        while (true) {

            try {

                $data = app(SystemMetricsCollector::class)->collect();

                // 1. Redis 历史
                app(MetricsBufferService::class)->push($data);

                // 2. WebSocket 推送
                broadcast(new SystemMetricsUpdated($data));

            } catch (\Throwable $e) {

                // 防止进程挂掉
                logger()->error('Metrics stream error', [
                    'message' => $e->getMessage()
                ]);
            }

            // 控制频率（关键）
            usleep(3000000); // 3秒
        }
    }
}
