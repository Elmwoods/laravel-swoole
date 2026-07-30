<?php

namespace App\Events\Ops;

use Illuminate\Broadcasting\Channel;
// implements ShouldBroadcast 会走队列 需执行 php artisan queue:work
// use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 系统监控实时推送事件
 */
class SystemMetricsUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public array $data
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
        return 'metrics.updated';
    }
}
