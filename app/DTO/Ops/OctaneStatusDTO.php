<?php

namespace App\DTO\Ops;

class OctaneStatusDTO
{
    public function __construct(
        public int $workers,
        public int $taskWorkers,
        public string $status,
    ) {
    }

    public function toArray(): array
    {
        return [
            'workers' => $this->workers,
            'task_workers' => $this->taskWorkers,
            'status' => $this->status,
        ];
    }
}
