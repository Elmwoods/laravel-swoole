<?php

namespace App\Services\Admin;

use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCsvExportService
{
    public function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value): string => $this->escapeCell($value), $row));
            }

            fclose($handle);
        }, $this->safeFilename($filename), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function escapeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $cell = (string) $value;

        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
            return "'{$cell}";
        }

        return $cell;
    }

    public function safeFilename(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $filename) ?: 'export.csv';
        $clean = trim($clean, '.-');

        return $clean !== '' ? $clean : 'export.csv';
    }
}
