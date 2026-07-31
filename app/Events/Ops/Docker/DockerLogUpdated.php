<?php

namespace App\Events\Ops\Docker;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Docker 容器日志实时推送事件。
 *
 * 向前端订阅了某容器日志的客户端推送新增日志片段。为规避广播单条消息的
 * payload 上限，只发送按字节安全截断后的预览，并用 truncated/bytes 标记原始长度，
 * 完整日志仍走 HTTP 接口按需拉取。
 */
class DockerLogUpdated implements ShouldBroadcast
{
    /**
     * Pusher/Reverb 单条消息有 payload 大小限制。
     * Docker 日志可能非常长，因此广播只发送一小段安全预览；
     * 完整日志统一通过 /api/ops/docker/logs/{id} HTTP 接口按需拉取。
     */
    private const MAX_LOG_PAYLOAD_BYTES = 6000;

    public string $containerId;   // 目标容器 ID（决定广播频道）

    public string $log;           // 实际推送的日志（可能已被安全截断）

    public int $originalBytes;    // 截断前的原始字节数（供前端判断是否有省略）

    public function __construct(string $containerId, string $log)
    {
        $this->containerId = $containerId;
        $this->originalBytes = strlen($log);        // 先记录原始长度
        $this->log = $this->safeLogPayload($log);   // 再做按字节安全截断
    }

    /**
     * 按容器 ID 划分的日志频道，客户端只订阅自己关注的容器。
     */
    public function broadcastOn(): array
    {
        return [
            new Channel(
                "docker.logs.{$this->containerId}"
            ),
        ];
    }

    /**
     * 前端监听的事件名。
     */
    public function broadcastAs(): string
    {
        return 'log.updated';
    }

    /**
     * 广播载荷：截断后的日志片段 + 是否被截断/原始字节数等元信息。
     */
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
