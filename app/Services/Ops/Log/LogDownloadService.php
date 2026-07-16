<?php

namespace App\Services\Ops\Log;

use App\Services\Admin\AdminCsvExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogDownloadService
{
    public function __construct(
        private readonly AdminCsvExportService $csv,
    ) {}

    public function download(array $result, string $source): StreamedResponse
    {
        $filename = sprintf('ops-logs-%s-%s.csv', $source, now()->format('Ymd-His'));
        request()->merge([
            'download_source' => $result['source'] ?? $source,
            'download_count' => $result['entry_count'] ?? $result['count'] ?? count((array) ($result['lines'] ?? [])),
        ]);

        return $this->csv->stream($filename, [
            'source',
            'time',
            'level',
            'summary',
            'content',
        ], $this->rows($result));
    }

    private function rows(array $result): array
    {
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
