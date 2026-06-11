<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

class OctaneLogService
{
    public function latest(LogQueryDTO $dto): array
    {
        $file = storage_path('logs/octane.logs');

        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file);

        return array_slice(
            $lines,
            -$dto->lines
        );
    }
}
