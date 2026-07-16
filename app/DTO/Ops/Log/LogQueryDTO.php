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
        public ?string $source = null,
        public ?string $container = null,
        public int $page = 1,
        public int $perPage = 20,
        public ?string $level = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
