<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

class OctaneLogService
{
    public function __construct(
        private readonly LogFileReaderService $reader,
    ) {}

    /**
     * 获取 Octane / Swoole 日志。
     */
    public function latest(LogQueryDTO $dto): array
    {
        return $this->reader->read(
            storage_path('logs/octane.log'),
            $dto,
            'octane',
        );
    }
}
