<?php

namespace App\Services\Ops\Log;

use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisLogService
{
    /**
     * 获取 Redis SlowLog。
     *
     * Redis SLOWLOG GET 返回数组结构，不同扩展的返回键可能不同，
     * 这里统一格式化为前端可直接渲染的结构。
     */
    public function slowLogs(int $count = 100): array
    {
        try {
            $rows = Redis::command('SLOWLOG', ['GET', max(10, min($count, 1000))]);
        } catch (Throwable $e) {
            return [
                'source' => 'redis',
                'available' => false,
                'message' => $e->getMessage(),
                'entries' => [],
                'count' => 0,
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        $entries = collect($rows ?? [])
            ->map(function (array $row): array {
                $command = $row[3] ?? $row['command'] ?? [];

                return [
                    'id' => (int) ($row[0] ?? $row['id'] ?? 0),
                    'occurred_at' => date('Y-m-d H:i:s', (int) ($row[1] ?? $row['timestamp'] ?? time())),
                    'duration_ms' => round(((int) ($row[2] ?? $row['duration'] ?? 0)) / 1000, 3),
                    'command' => is_array($command) ? implode(' ', array_map('strval', $command)) : (string) $command,
                    'client' => (string) ($row[4] ?? $row['client'] ?? ''),
                    'client_name' => (string) ($row[5] ?? $row['client_name'] ?? ''),
                ];
            })
            ->values()
            ->all();

        return [
            'source' => 'redis',
            'available' => true,
            'entries' => $entries,
            'count' => count($entries),
            'checked_at' => now()->toDateTimeString(),
        ];
    }
}
