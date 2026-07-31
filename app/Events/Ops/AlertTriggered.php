<?php

namespace App\Events\Ops;

use App\Models\OpsAlert;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Ops 告警实时推送事件。
 *
 * WebSocket 只发送告警摘要，详情列表仍通过 HTTP 分页接口读取，避免 payload 过大。
 */
class AlertTriggered implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public OpsAlert $alert   // 触发广播的告警模型（SerializesModels 会按主键序列化，重建时重新查库）
    ) {}

    /**
     * 公共运维告警频道。
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('ops.alerts'),
        ];
    }

    /**
     * 前端监听事件名。
     */
    public function broadcastAs(): string
    {
        return 'alert.triggered';
    }

    /**
     * 轻量广播结构。
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'source' => $this->alert->source,
            'severity' => $this->alert->severity,
            'title' => $this->alert->title,
            // 正文按显示宽度截断到 220，避免长文本撑大广播 payload
            'message' => mb_strimwidth($this->alert->message, 0, 220, '...'),
            'status' => $this->alert->status,
            'hit_count' => $this->alert->hit_count,
            // 时间可能为空，optional 安全取值后格式化为字符串
            'last_seen_at' => optional($this->alert->last_seen_at)->toDateTimeString(),
        ];
    }
}
