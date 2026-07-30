<?php

namespace App\Jobs\Docker;

use App\Events\Ops\Docker\DockerLogUpdated;
use App\Services\Ops\Docker\DockerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CollectDockerLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 防止 Docker 日志采集任务因为网络或 Docker Socket 阻塞太久。
     */
    public int $timeout = 30;

    public function handle(DockerService $service): void
    {
        foreach ($service->containers() as $c) {
            // 记录容器信息
            //            Log::info('Processing container', ['container' => $c]);

            $logs = $service->logs($c['full_id'], 10);

            // 记录日志内容（注意可能很长，可以截取或记录长度）
            //            Log::info('Docker logs retrieved', [
            //                'container_id' => $c['full_id'],
            //                'log_length' => strlen($logs),
            //                'log_preview' => substr($logs, 0, 500) // 前500字符
            //            ]);

            event(new DockerLogUpdated($c['full_id'], $logs));
        }
    }
}
