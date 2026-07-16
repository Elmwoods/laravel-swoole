<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;
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
    public function slowLogs(int|LogQueryDTO $query = 100): array
    {
        $dto = $query instanceof LogQueryDTO ? $query : new LogQueryDTO(lines: $query, perPage: min($query, 100));

        try {
            $rows = Redis::command('SLOWLOG', ['GET', max(10, min($dto->lines, 1000))]);
        } catch (Throwable $e) {
            return [
                'source' => 'redis',
                'available' => false,
                'message' => 'Redis 慢日志不可用',
                'entries' => [],
                'count' => 0,
                'pagination' => $this->pagination([], $dto),
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

        if ($dto->keyword) {
            $keyword = mb_strtolower($dto->keyword);
            $entries = array_values(array_filter($entries, function (array $entry) use ($keyword): bool {
                return str_contains(mb_strtolower($entry['command']), $keyword)
                    || str_contains(mb_strtolower($entry['client']), $keyword)
                    || str_contains(mb_strtolower($entry['client_name']), $keyword);
            }));
        }

        if ($dto->from || $dto->to) {
            $entries = $this->filterByTimeRange($entries, $dto);
        }

        $pagination = $this->pagination($entries, $dto);
        $pagedEntries = array_slice(
            $entries,
            ($pagination['current_page'] - 1) * $pagination['per_page'],
            $pagination['per_page'],
        );

        return [
            'source' => 'redis',
            'available' => true,
            'entries' => array_values($pagedEntries),
            'count' => count($entries),
            'pagination' => $pagination,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function filterByTimeRange(array $entries, LogQueryDTO $dto): array
    {
        $from = $dto->from ? strtotime($dto->from) : null;
        $to = $dto->to ? strtotime($dto->to) : null;

        return array_values(array_filter($entries, function (array $entry) use ($from, $to): bool {
            $time = strtotime((string) ($entry['occurred_at'] ?? ''));

            if ($time === false) {
                return false;
            }

            if ($from !== null && $time < $from) {
                return false;
            }

            if ($to !== null && $time > $to) {
                return false;
            }

            return true;
        }));
    }

    private function pagination(array $entries, LogQueryDTO $dto): array
    {
        $total = count($entries);
        $perPage = max(5, min($dto->perPage, 100));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = max(1, min($dto->page, $lastPage));

        return [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $currentPage < $lastPage,
        ];
    }
}
