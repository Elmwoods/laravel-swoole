<?php

namespace App\Events\Ops;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * 广播链路联调测试事件。
 *
 * 仅用于验证 WebSocket 广播是否打通：复用系统监控频道与事件名，向前端
 * 发送任意 data 载荷。非生产业务事件。
 */
class TestEvent implements ShouldBroadcast
{
    public function __construct(
        public array $data   // 任意测试载荷，原样广播
    ) {}

    /**
     * 复用系统监控频道进行联调。
     */
    public function broadcastOn(): array
    {

        return [
            new Channel('ops.system.metrics'),
        ];
    }

    /**
     * 前端监听的事件名（与系统监控一致，便于就地测试）。
     */
    public function broadcastAs(): string
    {
        return 'metrics.updated';
    }
}
