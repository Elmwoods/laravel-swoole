<?php

namespace App\Console\Commands\Ops;

use App\Events\Ops\NetworkMetricsUpdated;
use App\Services\Ops\NetworkTrafficService;
use Illuminate\Console\Command;

/**
 * 系统监控流（常驻进程）
 */
class PushNetworkMetrics extends Command
{
    protected $signature = 'ops:network:push';

    protected $description = 'Start network streaming';

    public function handle(): void
    {
        $this->info('Network started...'.time());

        while (true) {

            try {

                $data = app(NetworkTrafficService::class)->getSpeed();

                // WebSocket 推送
                broadcast(new NetworkMetricsUpdated($data));

            } catch (\Throwable $e) {

                // 防止进程挂掉
                logger()->error('Network stream error', [
                    'message' => $e->getMessage(),
                ]);
            }

            // 控制频率（关键）
            usleep(3000000); // 3秒
        }
    }
}
