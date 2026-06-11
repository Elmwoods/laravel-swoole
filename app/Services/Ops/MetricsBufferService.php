<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 历史缓存
 */
class MetricsBufferService
{
    const string KEY = 'ops:metrics:system';

    public function push(array $data): void
    {
        $data['time'] = now()->toDateTimeString();

        Redis::lpush(self::KEY, json_encode($data));
        Redis::ltrim(self::KEY, 0, 59);
    }

    public function history(): array
    {
        $list = Redis::lrange(self::KEY, 0, 29);

        return array_map(fn($i) => json_decode($i, true), $list);
    }
}
