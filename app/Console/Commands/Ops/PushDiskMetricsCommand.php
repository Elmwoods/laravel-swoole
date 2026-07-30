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
