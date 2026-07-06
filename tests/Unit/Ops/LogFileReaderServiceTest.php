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
        $this->assertSame('2026-07-06 10:00:00', $result['entries'][0]['time']);
        $this->assertSame('ERROR', $result['entries'][0]['level']);
        $this->assertCount(2, $result['entries'][0]['lines']);
        $this->assertSame(2, $result['entries'][0]['line_count']);
        $this->assertFalse($result['entries'][0]['truncated']);
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
        $this->assertStringContainsString('sixth', $result['entries'][0]['content']);
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
