<?php

namespace Tests\Unit\Ops;

use App\DTO\Ops\Log\LogQueryDTO;
use App\Services\Ops\Log\LogFileReaderService;
use Tests\TestCase;

/**
 * 日志文件读取服务测试。
 *
 * 覆盖 Laravel 多行异常按时间戳聚合，以及关键词命中时返回整条日志事件。
 */
class LogFileReaderServiceTest extends TestCase
{
    public function test_laravel_log_lines_are_grouped_by_timestamp(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.ERROR: first error',
            '#0 /var/www/html/app/Foo.php(10): boom',
            '[2026-07-06 10:01:00] local.INFO: queue processed',
        ]);

        $result = app(LogFileReaderService::class)->tail($file, new LogQueryDTO(lines: 20), 'laravel');

        $this->assertSame(2, $result['entry_count']);
        $this->assertSame('2026-07-06 10:01:00', $result['entries'][0]['time']);
        $this->assertSame('2026-07-06 10:00:00', $result['entries'][1]['time']);
        $this->assertSame('ERROR', $result['entries'][1]['level']);
        $this->assertCount(2, $result['entries'][1]['lines']);
        $this->assertSame(2, $result['entries'][1]['line_count']);
        $this->assertFalse($result['entries'][1]['truncated']);
        $this->assertSame('ERROR', $result['facets']['levels'][0]['name']);
        $this->assertSame('2026-07-06 10:00', $result['facets']['timeline'][0]['bucket']);
    }

    public function test_keyword_filter_keeps_complete_matched_entry(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.ERROR: first error',
            '#0 /var/www/html/app/Foo.php(10): target_keyword',
            '[2026-07-06 10:01:00] local.INFO: queue processed',
        ]);

        $result = app(LogFileReaderService::class)->tail(
            $file,
            new LogQueryDTO(lines: 20, keyword: 'target_keyword'),
            'laravel',
        );

        $this->assertSame(1, $result['entry_count']);
        $this->assertSame('ERROR', $result['entries'][0]['level']);
        $this->assertCount(2, $result['entries'][0]['lines']);
    }

    public function test_entries_are_paginated(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.INFO: first',
            '[2026-07-06 10:01:00] local.INFO: second',
            '[2026-07-06 10:02:00] local.INFO: third',
            '[2026-07-06 10:03:00] local.INFO: fourth',
            '[2026-07-06 10:04:00] local.INFO: fifth',
            '[2026-07-06 10:05:00] local.INFO: sixth',
        ]);

        $result = app(LogFileReaderService::class)->tail(
            $file,
            new LogQueryDTO(lines: 20, page: 2, perPage: 5),
            'laravel',
        );

        $this->assertSame(6, $result['entry_count']);
        $this->assertSame(2, $result['pagination']['current_page']);
        $this->assertSame(5, $result['pagination']['per_page']);
        $this->assertSame(1, count($result['entries']));
        $this->assertSame(1, count($result['lines']));
        $this->assertStringContainsString('first', $result['entries'][0]['content']);
    }

    public function test_full_mode_paginates_entries_beyond_tail_limit(): void
    {
        $lines = [];

        for ($index = 1; $index <= 1205; $index += 1) {
            $lines[] = sprintf('[2026-07-06 10:%02d:%02d] local.INFO: full entry %04d', intdiv($index, 60) % 60, $index % 60, $index);
        }

        $file = $this->fakeLogFile($lines);

        $result = app(LogFileReaderService::class)->read(
            $file,
            new LogQueryDTO(mode: 'full', page: 61, perPage: 20),
            'laravel',
        );

        $this->assertSame(1205, $result['entry_count']);
        $this->assertSame(61, $result['pagination']['current_page']);
        $this->assertSame(61, $result['pagination']['last_page']);
        $this->assertFalse($result['pagination']['has_more']);
        $this->assertCount(5, $result['entries']);
        $this->assertStringContainsString('full entry 0005', $result['entries'][0]['content']);
        $this->assertStringContainsString('full entry 0001', $result['entries'][4]['content']);
    }

    public function test_full_mode_first_page_and_last_page_return_distinct_entries(): void
    {
        $lines = [];

        for ($index = 1; $index <= 45; $index += 1) {
            $lines[] = sprintf('[2026-07-06 11:%02d:00] local.INFO: full page entry %04d', $index % 60, $index);
        }

        $file = $this->fakeLogFile($lines);
        $reader = app(LogFileReaderService::class);

        $firstPage = $reader->read(
            $file,
            new LogQueryDTO(mode: 'full', page: 1, perPage: 20),
            'laravel',
        );
        $lastPage = $reader->read(
            $file,
            new LogQueryDTO(mode: 'full', page: 3, perPage: 20),
            'laravel',
        );

        $this->assertSame(1, $firstPage['pagination']['current_page']);
        $this->assertSame(3, $lastPage['pagination']['current_page']);
        $this->assertSame(3, $lastPage['pagination']['last_page']);
        $this->assertCount(20, $firstPage['entries']);
        $this->assertCount(5, $lastPage['entries']);
        $this->assertStringContainsString('full page entry 0045', $firstPage['entries'][0]['content']);
        $this->assertStringContainsString('full page entry 0005', $lastPage['entries'][0]['content']);
        $this->assertNotSame($firstPage['entries'][0]['content'], $lastPage['entries'][0]['content']);
    }

    public function test_tail_mode_orders_recent_window_by_latest_time_first(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.INFO: old',
            '[2026-07-06 10:01:00] local.INFO: middle',
            '[2026-07-06 10:02:00] local.INFO: newest',
        ]);

        $result = app(LogFileReaderService::class)->tail(
            $file,
            new LogQueryDTO(lines: 20, page: 1, perPage: 20),
            'laravel',
        );

        $this->assertStringContainsString('newest', $result['entries'][0]['content']);
        $this->assertStringContainsString('middle', $result['entries'][1]['content']);
        $this->assertStringContainsString('old', $result['entries'][2]['content']);
    }

    public function test_latest_time_order_is_applied_after_filters_and_before_pagination(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.ERROR: target older error',
            '[2026-07-06 10:01:00] local.INFO: target ignored info',
            '[2026-07-06 10:02:00] local.ERROR: target newer error',
            '[2026-07-06 10:03:00] local.ERROR: other newest error',
        ]);

        $result = app(LogFileReaderService::class)->read(
            $file,
            new LogQueryDTO(
                keyword: 'target',
                level: 'ERROR',
                from: '2026-07-06 10:00:00',
                to: '2026-07-06 10:03:00',
                mode: 'full',
                page: 1,
                perPage: 5,
            ),
            'laravel',
        );

        $this->assertSame(2, $result['entry_count']);
        $this->assertSame(1, $result['pagination']['last_page']);
        $this->assertStringContainsString('target newer error', $result['entries'][0]['content']);
        $this->assertStringContainsString('target older error', $result['entries'][1]['content']);
    }

    public function test_same_time_entries_keep_file_order_and_timeless_entries_go_last(): void
    {
        $file = $this->fakeLogFile([
            'timeless boot line',
            '[2026-07-06 10:00:00] local.INFO: first same time',
            '[2026-07-06 10:00:00] local.INFO: second same time',
            '[2026-07-06 10:01:00] local.INFO: newest timed',
        ]);

        $result = app(LogFileReaderService::class)->read(
            $file,
            new LogQueryDTO(mode: 'full', page: 1, perPage: 20),
            'system',
        );

        $this->assertStringContainsString('newest timed', $result['entries'][0]['content']);
        $this->assertStringContainsString('first same time', $result['entries'][1]['content']);
        $this->assertStringContainsString('second same time', $result['entries'][2]['content']);
        $this->assertStringContainsString('timeless boot line', $result['entries'][3]['content']);
    }

    public function test_full_export_returns_all_matched_entries_without_pagination(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.INFO: export entry 1',
            '[2026-07-06 10:01:00] local.INFO: export entry 2',
            '[2026-07-06 10:02:00] local.INFO: export entry 3',
        ]);

        $result = app(LogFileReaderService::class)->read(
            $file,
            new LogQueryDTO(mode: 'full', page: 1, perPage: 1, forExport: true),
            'laravel',
        );

        $this->assertSame(3, $result['entry_count']);
        $this->assertSame(3, $result['pagination']['total']);
        $this->assertSame(1, $result['pagination']['last_page']);
        $this->assertCount(3, $result['entries']);
        $this->assertStringContainsString('export entry 3', $result['entries'][0]['content']);
        $this->assertStringContainsString('export entry 1', $result['entries'][2]['content']);
    }

    public function test_level_filter_is_applied_before_pagination(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.INFO: first',
            '[2026-07-06 10:01:00] local.ERROR: second',
            '[2026-07-06 10:02:00] local.ERROR: third',
        ]);

        $result = app(LogFileReaderService::class)->tail(
            $file,
            new LogQueryDTO(lines: 20, page: 1, perPage: 5, level: 'ERROR'),
            'laravel',
        );

        $this->assertSame(2, $result['entry_count']);
        $this->assertSame('ERROR', $result['entries'][0]['level']);
        $this->assertSame(2, $result['pagination']['total']);
    }

    public function test_time_range_filter_is_applied_before_pagination(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 09:59:00] local.INFO: too early',
            '[2026-07-06 10:00:00] local.ERROR: inside range',
            '[2026-07-06 10:10:00] local.INFO: also inside',
            '[2026-07-06 10:31:00] local.INFO: too late',
        ]);

        $result = app(LogFileReaderService::class)->tail(
            $file,
            new LogQueryDTO(
                lines: 20,
                from: '2026-07-06 10:00:00',
                to: '2026-07-06 10:30:00',
            ),
            'laravel',
        );

        $this->assertSame(2, $result['entry_count']);
        $this->assertStringContainsString('also inside', $result['entries'][0]['content']);
        $this->assertStringContainsString('inside range', $result['entries'][1]['content']);
        $this->assertStringNotContainsString('too early', implode("\n", $result['lines']));
        $this->assertStringNotContainsString('too late', implode("\n", $result['lines']));
    }

    public function test_unreadable_result_does_not_expose_full_file_path(): void
    {
        $path = sys_get_temp_dir().'/missing-sensitive-log-file.log';

        @unlink($path);

        $result = app(LogFileReaderService::class)->tail($path, new LogQueryDTO(lines: 20), 'system:secret');

        $this->assertNull($result['path']);
        $this->assertFalse($result['exists']);
        $this->assertFalse($result['readable']);
        $this->assertStringNotContainsString($path, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_long_entry_returns_preview_lines(): void
    {
        $file = $this->fakeLogFile([
            '[2026-07-06 10:00:00] local.Error: large exception',
            'line 1',
            'line 2',
            'line 3',
            'line 4',
            'line 5',
            'line 6',
            'line 7',
            'line 8',
            'line 9',
        ]);

        $result = app(LogFileReaderService::class)->tail($file, new LogQueryDTO(lines: 20), 'laravel');

        $this->assertSame('ERROR', $result['entries'][0]['level']);
        $this->assertSame(10, $result['entries'][0]['line_count']);
        $this->assertCount(8, $result['entries'][0]['preview_lines']);
        $this->assertTrue($result['entries'][0]['truncated']);
    }

    public function test_laravel_array_dump_lines_are_cleaned_before_grouping(): void
    {
        $file = $this->fakeLogFile([
            "',",
            "  146 => ')  ",
            "',",
            "  147 => '[2026-07-06 03:09:45] local.ERROR: Swoole\\\\Process::kill(): failed",
            "',",
            "  148 => '[stacktrace]",
            "',",
            "  149 => '#0 /var/www/html/vendor/foo.php(10): call",
            "',",
            "  150 => '#1 /var/www/html/artisan(16): run",
            "',",
            '  179 => \'\\',
            "',",
            '  180 => \'  191 => \\\\',
            "',",
            '  181 => \'\\',
            "',",
            "  151 => '\"} ",
        ]);

        $result = app(LogFileReaderService::class)->tail($file, new LogQueryDTO(lines: 20), 'laravel');

        $this->assertSame(1, $result['entry_count']);
        $this->assertSame('2026-07-06 03:09:45', $result['entries'][0]['time']);
        $this->assertSame('ERROR', $result['entries'][0]['level']);
        $this->assertStringStartsWith('[2026-07-06 03:09:45]', $result['entries'][0]['lines'][0]);
        $this->assertStringContainsString('[stacktrace]', $result['entries'][0]['content']);
        $this->assertStringNotContainsString('179 =>', $result['entries'][0]['content']);
        $this->assertStringNotContainsString('180 =>', $result['entries'][0]['content']);
        $this->assertStringNotContainsString('181 =>', $result['entries'][0]['content']);
    }

    public function test_laravel_self_reference_debug_entries_are_dropped(): void
    {
        $file = $this->fakeLogFile([
            "  199 => '[2026-07-06 03:31:13] local.DEBUG: /var/www/html/storage/logs/laravel.log",
            "',",
            '[2026-07-06 03:32:00] local.ERROR: real error',
            '[stacktrace]',
            '#0 /var/www/html/app/Foo.php(10): boom',
            '[2026-07-06 03:33:00] local.DEBUG: /var/www/html/storage/logs/laravel.log',
        ]);

        $result = app(LogFileReaderService::class)->tail($file, new LogQueryDTO(lines: 20), 'laravel');

        $this->assertSame(1, $result['entry_count']);
        $this->assertSame('ERROR', $result['entries'][0]['level']);
        $this->assertStringContainsString('real error', $result['entries'][0]['content']);
        $this->assertStringNotContainsString('/var/www/html/storage/logs/laravel.log', $result['entries'][0]['content']);
    }

    /**
     * 创建临时日志文件。
     */
    private function fakeLogFile(array $lines): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ops-log-test-');
        file_put_contents($file, implode(PHP_EOL, $lines));

        return $file;
    }
}
