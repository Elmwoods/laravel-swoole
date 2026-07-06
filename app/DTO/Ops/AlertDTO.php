<?php

namespace App\DTO\Ops;

/**
 * 告警数据传输对象。
 *
 * Service 之间只传递明确字段，避免直接把采集服务的完整响应塞进告警中心。
 */
readonly class AlertDTO
{
    public function __construct(
        public string $source,
        public string $severity,
        public string $title,
        public string $message,
        public array $context = [],
    ) {}

    /**
     * 生成稳定指纹。
     *
     * 相同来源、级别、标题和关键对象会复用同一条 open 告警，只增加 hit_count。
     */
    public function fingerprint(): string
    {
        $target = (string) ($this->context['target'] ?? $this->context['mount'] ?? $this->context['queue'] ?? '');

        return sha1(implode('|', [
            $this->source,
            $this->severity,
            $this->title,
            $target,
        ]));
    }

    /**
     * 转成模型可保存数组。
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint(),
            'source' => $this->source,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
