<?php

namespace App\Console\Commands\Ops;

use App\Events\Ops\NetworkMetricsUpdated;
use App\Services\Ops\NetworkTrafficService;
use Illuminate\Console\Command;

/**
 * 系统监控流（常驻进程）
 *
 * 作用：常驻 worker，进入 while(true) 死循环，每隔 3 秒（usleep 3_000_000）
 * 通过 NetworkTrafficService::getSpeed() 采集一次网络实时速率，并广播
 * NetworkMetricsUpdated 事件经 WebSocket 推送给前端。
 *
 * $signature：ops:network:push，无选项/参数。
 * 运行方式：不走 Laravel 调度器，作为 Supervisor 管理的长驻进程运行。
 *
 * 容错：单轮采集/推送异常在循环内被捕获并写 logger error，不让进程挂掉；
 * 无论成功失败每轮末尾都 sleep 3 秒控制推送频率。
 */
class PushNetworkMetrics extends Command
{
    protected $signature = 'ops:network:push';

    protected $description = 'Start network streaming';

    public function handle(): void
    {
        $this->info('Network started...'.time());

        // 常驻死循环：进程存活期间不断采集并推送网络速率
        while (true) {

            try {

                // 采集一次网络实时速率快照
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
