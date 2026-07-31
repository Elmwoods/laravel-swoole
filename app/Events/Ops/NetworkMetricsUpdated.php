<?php

namespace App\Events\Ops;

use Illuminate\Broadcasting\Channel;
// implements ShouldBroadcast 会走队列 需执行 php artisan queue:work
// use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 网络流量监控实时推送事件
 */
class NetworkMetricsUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public array $data   // 网络流量指标载荷（收发速率等），原样广播
    ) {}

    /**
     * 广播频道
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('ops.system.metrics'),
        ];
    }

    /**
     * 推送数据
     */
    public function broadcastAs(): string
    {
        return 'network.updated';
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
