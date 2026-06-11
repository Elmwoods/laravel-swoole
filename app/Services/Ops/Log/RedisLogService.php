<?php

namespace App\Services\Ops\Log;

use Illuminate\Support\Facades\Redis;

class RedisLogService
{
    /**
     * 获取Redis SlowLog
     */
    public function slowLogs(
        int $count = 100
    ): array {
        return Redis::command(
            'SLOWLOG',
            ['GET', $count]
        );
    }
}
