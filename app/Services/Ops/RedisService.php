<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 状态监控
 */
class RedisService
{
    public function info(): array
    {
        try {
            $info = Redis::command('INFO');

            return [
                'connected' => true,
                'used_memory' => $info['used_memory_human'] ?? null,
                'clients' => $info['connected_clients'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
