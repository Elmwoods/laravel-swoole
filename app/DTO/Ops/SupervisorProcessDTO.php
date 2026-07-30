<?php

namespace App\DTO\Ops;

class SupervisorProcessDTO
{
    public function __construct(
        public string $name,
        public string $status,
        public string $description,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'description' => $this->description,
        ];
    }
}
