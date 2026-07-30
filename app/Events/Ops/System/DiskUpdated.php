<?php

namespace App\Events\Ops\System;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * DiskUpdated
 * ---------------------------------------
 * 磁盘监控实时推送事件
 * ---------------------------------------
 */
class DiskUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public array $payload
    ) {}

    /**
     * 广播频道（公共监控频道）
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('ops.system.disk'),
        ];
    }

    /**
     * 事件名称
     */
    public function broadcastAs(): string
    {
        return 'disk.updated';
    }

    /**
     * 推送数据结构
     */
    public function broadcastWith(): array
    {
        return [
            'data' => $this->payload,
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}
