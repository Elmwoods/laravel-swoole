<?php

namespace App\Events\Ops\Docker;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;


class DockerLogUpdated implements ShouldBroadcast
{
    public function __construct(
        public string $containerId,
        public string $log
    ) {}

    public function broadcastOn():array
    {
        return [
            new Channel(
                "docker.logs.{$this->containerId}"
            )
        ];
    }

    public function broadcastAs(): string
    {
        return 'log.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'container_id' => $this->containerId,
            'log' => $this->log,
            'time' => now()->toDateTimeString(),
        ];
    }
}
