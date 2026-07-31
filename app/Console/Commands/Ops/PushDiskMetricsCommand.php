<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\System\DiskService;
use Illuminate\Console\Command;

/**
 * PushDiskMetricsCommand
 * ----------------------------------------
 * Supervisor 常驻执行命令
 * 用于持续推送磁盘监控数据
 * ----------------------------------------
 *
 * 作用：由 Supervisor 拉起的常驻 worker，进入 while(true) 死循环，
 * 每隔约 3 秒调用 DiskService::push() 采集并经 WebSocket 推送一次磁盘指标。
 *
 * $signature：ops:push-disk-metrics，无选项/参数。
 * 运行方式：不走 Laravel 调度器，而是作为 Supervisor 管理的长驻进程；进程退出后由 Supervisor 负责重启。
 *
 * 容错：单轮采集/推送异常在循环内被捕获，仅打印 error 后 sleep(5) 再继续，
 * 不让进程崩溃退出，从而避免 Supervisor 频繁重启导致的 crash loop。
 */
class PushDiskMetricsCommand extends Command
{
    /**
     * artisan 命令名称
     */
    protected $signature = 'ops:push-disk-metrics';

    /**
     * 描述
     */
    protected $description = 'Supervisor push disk metrics to websocket';

    public function handle(DiskService $diskService): int
    {
        $this->info('[DiskMetrics] Worker started...');

        // 常驻死循环：进程存活期间不断采集并推送磁盘指标
        while (true) {

            try {
                // 采集 + 推送
                $data = $diskService->push();

                $this->info('[DiskMetrics] pushed at '.now());

                // 控制频率（企业级建议 2~5 秒）
                sleep(3);

            } catch (\Throwable $e) {

                $this->error('[DiskMetrics ERROR] '.$e->getMessage());

                // 防止 crash loop
                sleep(5);
            }
        }
    }
}
