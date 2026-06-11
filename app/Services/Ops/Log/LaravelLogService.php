<?php

namespace App\Services\Ops\Log;


use App\DTO\Ops\Log\LogQueryDTO;

class LaravelLogService
{
    /**
     * 获取Laravel日志
     */
    public function latest(LogQueryDTO $dto): array
    {
        $file = storage_path('logs/laravel.log');

        logger($file);
        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file);

        $logs = array_slice(
            $lines,
            -$dto->lines
        );

        if ($dto->keyword) {
            $logs = array_filter(
                $logs,
                fn ($line) => str_contains(
                    strtolower($line),
                    strtolower($dto->keyword)
                )
            );
        }

        logger($logs);
        return array_values($logs);
    }
}
