<?php

namespace App\Events\Ops;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class TestEvent implements ShouldBroadcast
{
    public function __construct(
        public array $data
    ) {}

    public function broadcastOn(): array
    {

        return [
            new Channel('ops.system.metrics'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'metrics.updated';
    }
}
