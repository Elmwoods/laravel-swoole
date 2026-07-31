<?php

namespace App\Services\Ops\Log;

use App\Services\Admin\AdminCsvExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 日志导出（CSV 下载）服务。
 *
 * 把任意日志来源（laravel/octane/system/docker/redis）已经查询好的结果数组，
 * 归一成统一的 CSV 列并流式下载。复用 AdminCsvExportService 做真正的流式输出，
 * 本类只负责列定义、行映射与不同来源字段名的兼容。
 */
class LogDownloadService
{
    /**
     * 作用：注入通用的 CSV 流式导出服务。
     *
     * @param  AdminCsvExportService  $csv  负责按表头 + 行数据流式生成 CSV 响应
     */
    public function __construct(
        private readonly AdminCsvExportService $csv,
    ) {}

    /**
     * 把日志查询结果导出为 CSV 下载响应。
     *
     * 作用：生成带来源与时间戳的文件名，注入审计用的下载元信息，再流式输出统一列的 CSV。
     *
     * @param  array  $result  某个日志服务返回的查询结果（可能含 entries 或仅 lines）
     * @param  string  $source  来源标识，用于文件名与结果缺省来源
     * @return StreamedResponse 流式 CSV 下载响应
     */
    public function download(array $result, string $source): StreamedResponse
    {
        // 文件名带来源与精确到秒的时间戳，避免多次导出互相覆盖。
        $filename = sprintf('ops-logs-%s-%s.csv', $source, now()->format('Ymd-His'));
        // 把来源与条数并入当前请求，供 CSV 服务的下载审计/日志读取（优先事件数，退回行数，再退回原始行数组长度）。
        request()->merge([
            'download_source' => $result['source'] ?? $source,
            'download_count' => $result['entry_count'] ?? $result['count'] ?? count((array) ($result['lines'] ?? [])),
        ]);

        // 统一的 5 列表头，屏蔽各来源结构差异，导出格式对前端保持一致。
        return $this->csv->stream($filename, [
            'source',
            'time',
            'level',
            'summary',
            'content',
        ], $this->rows($result));
    }

    /**
     * 把查询结果拍平成 CSV 行数组。
     *
     * 作用：优先用结构化的 entries 映射成行；没有 entries 时退回到原始 lines。
     *
     * 为什么：不同来源字段名不一致——文件日志用 time/summary/content，redis 慢日志用
     * occurred_at/command，所以这里用 ?? 逐个兜底，保证任一来源都能落到统一的 5 列。
     *
     * @param  array  $result  日志查询结果
     * @return array 每项为对应 [source,time,level,summary,content] 的行数组
     */
    private function rows(array $result): array
    {
        // 有结构化日志事件时，逐条映射；字段名按 文件日志 → redis 慢日志 → 原始行 的顺序兜底。
        if (! empty($result['entries']) && is_array($result['entries'])) {
            return collect($result['entries'])
                ->map(fn (array $entry): array => [
                    $result['source'] ?? '',
                    $entry['time'] ?? $entry['occurred_at'] ?? '',
                    $entry['level'] ?? '',
                    $entry['summary'] ?? $entry['command'] ?? '',
                    $entry['content'] ?? $entry['command'] ?? implode(PHP_EOL, (array) ($entry['lines'] ?? [])),
                ])
                ->values()
                ->all();
        }

        // 没有事件结构（如纯文本来源）时退回逐行导出：level/time 留空，summary 截断预览、content 存整行。
        return collect((array) ($result['lines'] ?? []))
            ->map(fn (string $line): array => [
                $result['source'] ?? '',
                '',
                '',
                mb_strimwidth($line, 0, 120, '...'),
                $line,
            ])
            ->values()
            ->all();
    }
}
