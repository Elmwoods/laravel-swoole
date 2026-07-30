<?php

namespace App\Events\Ops\Docker;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class DockerLogUpdated implements ShouldBroadcast
{
    /**
     * Pusher/Reverb 单条消息有 payload 大小限制。
     * Docker 日志可能非常长，因此广播只发送一小段安全预览；
     * 完整日志统一通过 /api/ops/docker/logs/{id} HTTP 接口按需拉取。
     */
    private const MAX_LOG_PAYLOAD_BYTES = 6000;

    public string $containerId;

    public string $log;

    public int $originalBytes;

    public function __construct(string $containerId, string $log)
    {
        $this->containerId = $containerId;
        $this->originalBytes = strlen($log);
        $this->log = $this->safeLogPayload($log);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel(
                "docker.logs.{$this->containerId}"
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'log.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'new_logs_available',
            'log' => $this->log,
            'container_id' => $this->containerId,
            'truncated' => $this->originalBytes > strlen($this->log),
            'bytes' => $this->originalBytes,
            'time' => now()->toDateTimeString(),
        ];
    }

    /**
     * 按字节限制日志体，避免中文/多字节字符被截坏。
     */
    private function safeLogPayload(string $log): string
    {
        if (strlen($log) <= self::MAX_LOG_PAYLOAD_BYTES) {
            return $log;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($log, 0, self::MAX_LOG_PAYLOAD_BYTES, 'UTF-8');
        }

        return substr($log, 0, self::MAX_LOG_PAYLOAD_BYTES);
    }
}
