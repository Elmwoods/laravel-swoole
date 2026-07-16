<?php

namespace App\Services\Ops\Log;


use App\DTO\Ops\Log\LogQueryDTO;

class LaravelLogService
{
    public function __construct(
        private readonly LogFileReaderService $reader,
    ) {}

    /**
     * 获取 Laravel 应用日志。
     */
    public function latest(LogQueryDTO $dto): array
    {
        return $this->reader->read(
            storage_path('logs/laravel.log'),
            $dto,
            'laravel',
        );
    }
}
