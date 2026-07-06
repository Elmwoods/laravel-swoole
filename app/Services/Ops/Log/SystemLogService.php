<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

/**
 * 系统日志服务。
 *
 * 系统日志只能从 config/ops.php 的白名单读取，不能让前端传任意路径。
 * 这能避免“日志查看”功能变成任意文件读取入口。
 */
class SystemLogService
{
    public function __construct(
        private readonly LogFileReaderService $reader,
    ) {}

    /**
     * 获取系统日志来源列表。
     */
    public function sources(): array
    {
        return collect(config('ops.logs.system_sources', []))
            ->map(fn (string $path, string $key): array => [
                'key' => $key,
                'path' => $path,
                'exists' => is_file($path),
                'readable' => is_readable($path),
            ])
            ->values()
            ->all();
    }

    /**
     * 读取指定系统日志来源。
     */
    public function latest(LogQueryDTO $dto): array
    {
        $sources = config('ops.logs.system_sources', []);
        $source = $dto->source ?: array_key_first($sources);
        $file = $sources[$source] ?? null;

        if (! $file) {
            return [
                'source' => $source,
                'path' => null,
                'exists' => false,
                'readable' => false,
                'message' => '系统日志来源未配置或不在白名单中',
                'lines' => [],
                'count' => 0,
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        return $this->reader->tail($file, $dto, 'system:'.$source);
    }
}
