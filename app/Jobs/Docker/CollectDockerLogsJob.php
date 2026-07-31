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

/**
 * Docker 容器日志采集任务。
 *
 * 职责：遍历当前所有 Docker 容器，逐个采集其最新日志，并通过广播事件
 * DockerLogUpdated 将日志实时推送出去（供运维前端订阅展示）。
 *
 * 队列与调度：
 * - 实现 ShouldQueue，为异步队列任务，通常由调度器周期性 dispatch。
 * - 使用 Dispatchable / InteractsWithQueue / Queueable / SerializesModels
 *   等标准 Trait，具备派发、队列交互、序列化等能力。
 *
 * 容错性：设置 $timeout = 30 秒，避免因网络或 Docker Socket 阻塞导致任务长期挂起。
 */
class CollectDockerLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 防止 Docker 日志采集任务因为网络或 Docker Socket 阻塞太久。
     */
    public int $timeout = 30;

    public function handle(DockerService $service): void
    {
        // 遍历所有容器，逐个采集其最近日志
        foreach ($service->containers() as $c) {
            // 记录容器信息
            //            Log::info('Processing container', ['container' => $c]);

            // 采集该容器最近 10 行日志（full_id 为完整容器 ID）
            $logs = $service->logs($c['full_id'], 10);

            // 记录日志内容（注意可能很长，可以截取或记录长度）
            //            Log::info('Docker logs retrieved', [
            //                'container_id' => $c['full_id'],
            //                'log_length' => strlen($logs),
            //                'log_preview' => substr($logs, 0, 500) // 前500字符
            //            ]);

            // 广播日志更新事件，将容器 ID 与最新日志实时推送给订阅方
            event(new DockerLogUpdated($c['full_id'], $logs));
        }
    }
}
