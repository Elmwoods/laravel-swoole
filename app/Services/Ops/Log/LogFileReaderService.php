<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

/**
 * 日志文件读取服务。
 *
 * 统一处理 tail、关键词过滤和返回结构，避免 Laravel / Octane / System
 * 日志服务重复读取文件逻辑。这里不使用 shell tail，避免命令拼接风险。
 */
class LogFileReaderService
{
    /**
     * 根据查询模式读取日志。
     */
    public function read(string $file, LogQueryDTO $dto, string $source): array
    {
        return $dto->mode === 'full'
            ? $this->full($file, $dto, $source)
            : $this->tail($file, $dto, $source);
    }

    /**
     * 读取文件末尾日志。
     */
    public function tail(string $file, LogQueryDTO $dto, string $source): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return $this->emptyResult($source, '日志文件不存在或不可读', is_file($file), is_readable($file));
        }

        $lines = $this->readLastLines($file, $this->normalizeLines($dto->lines));
        $result = $this->fromLines($lines, $dto, $source);

        return [
            ...$result,
            'path' => $file,
            'exists' => true,
            'readable' => true,
        ];
    }

    /**
     * 读取完整文件日志并分页返回。
     *
     * 这里按行迭代读取，避免使用 file() 一次性读取整个文件。
     */
    public function full(string $file, LogQueryDTO $dto, string $source): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return $this->emptyResult($source, '日志文件不存在或不可读', is_file($file), is_readable($file));
        }

        $result = $this->fromLines($this->readAllLines($file), $dto, $source);

        return [
            ...$result,
            'path' => $file,
            'exists' => true,
            'readable' => true,
            'mode' => 'full',
        ];
    }

    /**
     * 从已读取的日志行生成统一查询结果。
     */
    public function fromLines(array $lines, LogQueryDTO $dto, string $source): array
    {
        $entries = $this->toEntries($lines, $source);

        if ($dto->keyword) {
            $keyword = mb_strtolower($dto->keyword);
            $entries = array_values(array_filter($entries, function (array $entry) use ($keyword): bool {
                return str_contains(mb_strtolower($entry['content']), $keyword);
            }));
        }

        if ($dto->from || $dto->to) {
            $entries = $this->filterByTimeRange($entries, $dto);
        }

        $facets = $this->facets($entries, $source);

        if ($dto->level) {
            $level = mb_strtoupper($dto->level);
            $entries = array_values(array_filter($entries, function (array $entry) use ($level): bool {
                return mb_strtoupper((string) ($entry['level'] ?? 'INFO')) === $level;
            }));
        }

        if ($dto->keyword || $dto->level || $dto->from || $dto->to) {
            $lines = collect($entries)
                ->flatMap(fn (array $entry): array => $entry['lines'])
                ->values()
                ->all();
        }

        $entries = $this->sortLatestFirst($entries);

        $pagination = $dto->forExport
            ? $this->exportPagination($entries)
            : $this->pagination($entries, $dto);
        $pagedEntries = $dto->forExport
            ? $entries
            : array_slice(
                $entries,
                ($pagination['current_page'] - 1) * $pagination['per_page'],
                $pagination['per_page'],
            );
        $pagedEntries = array_map(
            fn (array $entry): array => $this->withPreview($entry),
            $pagedEntries,
        );
        $pagedLines = collect($pagedEntries)
            ->flatMap(fn (array $entry): array => $entry['lines'])
            ->values()
            ->all();

        return [
            'source' => $source,
            'path' => null,
            'exists' => true,
            'readable' => true,
            'lines' => $pagedLines,
            'entries' => array_values($pagedEntries),
            'facets' => $facets,
            'count' => count($lines),
            'entry_count' => count($entries),
            'pagination' => $pagination,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 按日志时间筛选；没有可解析时间的日志在时间筛选时不展示。
     */
    private function filterByTimeRange(array $entries, LogQueryDTO $dto): array
    {
        $from = $dto->from ? strtotime($dto->from) : null;
        $to = $dto->to ? strtotime($dto->to) : null;

        return array_values(array_filter($entries, function (array $entry) use ($from, $to): bool {
            $time = isset($entry['time']) ? strtotime((string) $entry['time']) : false;

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

    /**
     * 读取文件最后 N 行。
     *
     * 运维日志可能很大，所以从文件尾部按块读取，而不是 file() 全量载入。
     */
    private function readLastLines(string $file, int $lineCount): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        $buffer = '';
        $chunkSize = 8192;
        $position = -1;
        $linesFound = 0;

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);

        while ($fileSize + $position >= 0 && $linesFound <= $lineCount) {
            $seek = max($fileSize + $position - $chunkSize + 1, 0);
            $readSize = $fileSize + $position - $seek + 1;

            fseek($handle, $seek);
            $chunk = fread($handle, $readSize);

            if ($chunk === false) {
                break;
            }

            $buffer = $chunk.$buffer;
            $linesFound = substr_count($buffer, PHP_EOL);
            $position -= $chunkSize;
        }

        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', trim($buffer));

        if (! is_array($lines)) {
            return [];
        }

        return array_slice($lines, -$lineCount);
    }

    /**
     * 按行迭代读取完整日志文件。
     */
    private function readAllLines(string $file): array
    {
        $lines = [];
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $lines[] = rtrim($line, "\r\n");
        }

        fclose($handle);

        return $lines;
    }

    /**
     * 限制日志行数范围。
     */
    private function normalizeLines(int $lines): int
    {
        return max(10, min($lines, 1000));
    }

    /**
     * 生成日志事件分页信息。
     */
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

    /**
     * 按日志时间倒序排列；同一时间保持文件内顺序，无时间日志排在最后。
     */
    private function sortLatestFirst(array $entries): array
    {
        $indexedEntries = array_map(
            fn (array $entry, int $index): array => [
                'entry' => $entry,
                'index' => $index,
                'timestamp' => isset($entry['time']) ? strtotime((string) $entry['time']) : false,
            ],
            $entries,
            array_keys($entries),
        );

        usort($indexedEntries, function (array $left, array $right): int {
            $leftTime = $left['timestamp'];
            $rightTime = $right['timestamp'];
            $leftHasTime = $leftTime !== false;
            $rightHasTime = $rightTime !== false;

            if ($leftHasTime && $rightHasTime && $leftTime !== $rightTime) {
                return $rightTime <=> $leftTime;
            }

            if ($leftHasTime !== $rightHasTime) {
                return $leftHasTime ? -1 : 1;
            }

            return $left['index'] <=> $right['index'];
        });

        return array_map(fn (array $item): array => $item['entry'], $indexedEntries);
    }

    /**
     * 导出模式不分页，返回完整匹配结果。
     */
    private function exportPagination(array $entries): array
    {
        $total = count($entries);

        return [
            'current_page' => 1,
            'per_page' => max($total, 1),
            'total' => $total,
            'last_page' => 1,
            'has_more' => false,
        ];
    }

    /**
     * 将原始日志行聚合成日志事件。
     *
     * Laravel 日志通常以 [YYYY-mm-dd HH:ii:ss] 开头，异常堆栈会跟在下一行。
     * 这里按时间戳切分，前端就能按“每条日志”展示，而不是把堆栈混成一大片文本。
     */
    private function toEntries(array $lines, string $source): array
    {
        $entries = [];

        foreach ($lines as $line) {
            $line = $this->normalizeLine($line, $source);

            if ($line === null) {
                continue;
            }

            $parsed = $this->parseLineHeader($line, $source);

            if ($parsed !== null) {
                $entries[] = [
                    'time' => $parsed['time'],
                    'level' => $parsed['level'],
                    'summary' => $parsed['summary'],
                    'content' => $line,
                    'lines' => [$line],
                ];

                continue;
            }

            if ($entries === []) {
                if ($source === 'laravel') {
                    continue;
                }

                $entries[] = [
                    'time' => null,
                    'level' => 'INFO',
                    'summary' => mb_strimwidth($line, 0, 160, '...'),
                    'content' => $line,
                    'lines' => [$line],
                ];

                continue;
            }

            $lastIndex = count($entries) - 1;
            $entries[$lastIndex]['lines'][] = $line;
            $entries[$lastIndex]['content'] = implode(PHP_EOL, $entries[$lastIndex]['lines']);
        }

        return $entries;
    }

    /**
     * 清洗原始日志行。
     *
     * Laravel 日志里曾经写入过“日志数组转储”，实际响应会出现：
     * `147 => '[2026-07-06 03:09:45] local.ERROR: ...` 和大量 `',` 垃圾行。
     * 这里先剥离数组下标、忽略纯引号碎片，再从行内提取真实 Laravel 时间戳。
     */
    private function normalizeLine(string $line, string $source): ?string
    {
        $line = trim($line);
        $fromArrayDump = false;

        if ($line === '') {
            return null;
        }

        if ($source !== 'laravel') {
            return $line;
        }

        if (preg_match('/^[\\\\\']*,?$/', $line) || $line === ')' || $line === 'array (') {
            return null;
        }

        if (preg_match('/^\d+\s*=>\s*\'?(?<value>.*)$/', $line, $matches)) {
            $fromArrayDump = true;
            $line = $matches['value'];
        }

        $line = trim($line);
        $line = preg_replace('/\',?$/', '', $line) ?? $line;
        $line = str_replace(['\\\\\\\\', '\\\\'], '\\', $line);

        if (preg_match('/(?<log>\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+[A-Za-z0-9_-]+\.[A-Za-z]+:\s*.*)$/', $line, $matches)) {
            $line = $matches['log'];

            return $this->isLaravelSelfReferenceDebug($line) ? null : $line;
        }

        if (preg_match('/^\[stacktrace\]$/', $line) || preg_match('/^#\d+\s+/', $line) || str_starts_with($line, '"}')) {
            return $line;
        }

        if ($fromArrayDump) {
            return null;
        }

        if ($this->isLaravelSelfReferenceDebug($line)) {
            return null;
        }

        return $line;
    }

    /**
     * 过滤历史调试污染。
     *
     * 之前排查日志接口时，日志文件路径被写入到了 laravel.log。
     * 这类 DEBUG 记录不属于真实业务日志，展示出来会干扰分页和告警判断。
     */
    private function isLaravelSelfReferenceDebug(string $line): bool
    {
        return (bool) preg_match(
            '/\]\s+[A-Za-z0-9_-]+\.DEBUG:\s+.*storage\/logs\/laravel\.log\s*$/i',
            $line,
        );
    }

    /**
     * 为前端日志块生成预览信息。
     *
     * 长异常堆栈默认只展示前几行，点击后再显示完整内容。
     */
    private function withPreview(array $entry): array
    {
        $previewLineLimit = 8;
        $lines = $entry['lines'] ?? [];
        $entry['line_count'] = count($lines);
        $entry['preview_lines'] = array_slice($lines, 0, $previewLineLimit);
        $entry['truncated'] = count($lines) > $previewLineLimit;

        return $entry;
    }

    /**
     * 解析常见日志首行。
     */
    private function parseLineHeader(string $line, string $source): ?array
    {
        if ($source === 'laravel' && preg_match('/^\[(?<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(?<env>[A-Za-z0-9_-]+)\.(?<level>[A-Za-z]+):\s*(?<message>.*)$/', $line, $matches)) {
            return [
                'time' => $matches['time'],
                'level' => strtoupper($matches['level']),
                'summary' => mb_strimwidth($matches['message'], 0, 180, '...'),
            ];
        }

        if (preg_match('/^\[?(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]?[:\s-]+(?<message>.*)$/', $line, $matches)) {
            return [
                'time' => str_replace('T', ' ', $matches['time']),
                'level' => $this->detectLevel($line),
                'summary' => mb_strimwidth($matches['message'], 0, 180, '...'),
            ];
        }

        return null;
    }

    /**
     * 从日志内容中识别级别，用于前端标签颜色。
     */
    private function detectLevel(string $line): string
    {
        $upper = mb_strtoupper($line);

        foreach (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'] as $level) {
            if (str_contains($upper, $level)) {
                return $level;
            }
        }

        return 'INFO';
    }

    /**
     * 生成日志钻取所需的统计维度。
     *
     * 当前先提供级别分布与分钟级时间桶，后续接入 Loki / Prometheus 时，
     * 可以在这里继续扩展 service、host、trace_id 等标签。
     */
    private function facets(array $entries, string $source): array
    {
        $levels = [];
        $timeline = [];

        foreach ($entries as $entry) {
            $level = strtoupper((string) ($entry['level'] ?? 'INFO'));
            $levels[$level] = ($levels[$level] ?? 0) + 1;

            $bucket = $this->minuteBucket($entry['time'] ?? null);

            if ($bucket !== null) {
                $timeline[$bucket] = ($timeline[$bucket] ?? 0) + 1;
            }
        }

        ksort($timeline);

        return [
            'source' => $source,
            'levels' => collect($levels)
                ->map(fn (int $total, string $name): array => [
                    'name' => $name,
                    'total' => $total,
                ])
                ->sortByDesc('total')
                ->values()
                ->all(),
            'timeline' => collect($timeline)
                ->map(fn (int $total, string $bucket): array => [
                    'bucket' => $bucket,
                    'total' => $total,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * 将日志时间归并到分钟桶。
     */
    private function minuteBucket(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        return substr($time, 0, 16);
    }

    /**
     * 返回统一空结果。
     */
    public function emptyResult(string $source, string $message, bool $exists = false, bool $readable = false): array
    {
        return [
            'source' => $source,
            'path' => null,
            'exists' => $exists,
            'readable' => $readable,
            'message' => $message,
            'lines' => [],
            'entries' => [],
            'facets' => [
                'source' => $source,
                'levels' => [],
                'timeline' => [],
            ],
            'count' => 0,
            'entry_count' => 0,
            'pagination' => [
                'current_page' => 1,
                'per_page' => $this->normalizePerPage(20),
                'total' => 0,
                'last_page' => 1,
                'has_more' => false,
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 空结果也走相同 per_page 限制。
     */
    private function normalizePerPage(int $perPage): int
    {
        return max(5, min($perPage, 100));
    }
}
