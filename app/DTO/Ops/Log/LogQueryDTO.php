<?php

namespace App\DTO\Ops\Log;

/**
 * 日志查询DTO
 */
class LogQueryDTO
{
    public function __construct(
        public int $lines = 200,
        public ?string $keyword = null,
    ) {}
}
