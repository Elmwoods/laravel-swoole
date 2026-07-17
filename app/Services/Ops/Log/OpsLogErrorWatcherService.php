<?php

namespace App\Services\Ops\Log;

use App\Events\Ops\AlertTriggered;
use App\Models\OpsAlert;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Support\Facades\File;
use Throwable;

class OpsLogErrorWatcherService
{
    public function __construct(
        private readonly AlertNotificationService $notification,
    ) {}

    public function scan(array $sources = [], bool $dryRun = false, bool $resetOffsets = false): array
    {
        if (! (bool) config('ops.logs.error_watcher.enabled', true)) {
            return [
                'scanned' => 0,
                'detected' => 0,
                'events' => [],
                'enabled' => false,
            ];
        }

        $configured = $this->sources();
        $selected = $sources === [] ? array_keys($configured) : $sources;
        $state = $resetOffsets ? [] : $this->readState();
        $events = [];
        $scanned = 0;
        $maxEvents = max(1, (int) config('ops.logs.error_watcher.max_events_per_run', 50));

        foreach ($selected as $source) {
            if (! array_key_exists($source, $configured)) {
                continue;
            }

            $scanned += 1;
            $file = (string) $configured[$source];
            $read = $this->readNewBytes($source, $file, $state);

            if ($read['content'] === '') {
                continue;
            }

            foreach ($this->eventsFromContent($source, $read['content']) as $event) {
                $events[] = $event;

                if (count($events) >= $maxEvents) {
                    break 2;
                }
            }
        }

        $this->writeState($state);

        if (! $dryRun) {
            foreach ($events as $event) {
                $this->storeAlert($event);
            }
        }

        return [
            'scanned' => $scanned,
            'detected' => count($events),
            'events' => $events,
            'enabled' => true,
        ];
    }

    public function sources(): array
    {
        return (array) config('ops.logs.error_watcher.sources', []);
    }

    public function fingerprint(array $event): string
    {
        return sha1(implode('|', [
            'logs',
            $event['source'] ?? '',
            $event['level'] ?? '',
            $this->fingerprintText($event),
        ]));
    }

    private function readNewBytes(string $source, string $file, array &$state): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            unset($state[$source]);

            return ['content' => ''];
        }

        $size = filesize($file);
        $inode = fileinode($file);
        $previous = (array) ($state[$source] ?? []);
        $offset = (int) ($previous['offset'] ?? 0);

        $prefixHash = $this->filePrefixHash($file);

        if (
            ($previous['inode'] ?? null) !== $inode
            || $size < $offset
            || ($offset > 0 && ($previous['prefix_hash'] ?? null) !== $prefixHash)
        ) {
            $offset = 0;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return ['content' => ''];
        }

        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        fclose($handle);

        $state[$source] = [
            'inode' => $inode,
            'size' => $size,
            'offset' => $size,
            'hash' => sha1($file.'|'.$inode.'|'.$size),
            'prefix_hash' => $prefixHash,
            'checked_at' => now()->toDateTimeString(),
        ];

        return ['content' => is_string($content) ? $content : ''];
    }

    private function eventsFromContent(string $source, string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);

        if (! is_array($lines)) {
            return [];
        }

        $events = [];
        $current = null;
        $levels = array_map('strtoupper', (array) config('ops.logs.error_watcher.levels', []));

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($current !== null && $this->isStackContinuation($source, $line)) {
                $current['lines'][] = $line;

                continue;
            }

            $parsed = $this->parseErrorLine($line, $levels);

            if ($parsed !== null) {
                if ($current !== null) {
                    $events[] = $this->buildEvent($source, $current);
                }

                $current = [
                    'time' => $parsed['time'],
                    'level' => $parsed['level'],
                    'summary' => $parsed['summary'],
                    'lines' => [$line],
                    'rule' => $parsed['rule'],
                ];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $events[] = $this->buildEvent($source, $current);
        }

        return $this->coalesceBuildEvents($events);
    }

    private function parseErrorLine(string $line, array $levels): ?array
    {
        if (preg_match('/^\[(?<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+[A-Za-z0-9_-]+\.(?<level>[A-Za-z]+):\s*(?<message>.*)$/', $line, $matches)) {
            $level = strtoupper($matches['level']);

            if (! in_array($level, $levels, true)) {
                return null;
            }

            return [
                'time' => $matches['time'],
                'level' => $level,
                'summary' => mb_strimwidth($matches['message'], 0, 180, '...'),
                'rule' => 'laravel-level',
            ];
        }

        if (preg_match('/\b(?<level>EMERGENCY|CRITICAL|ERROR)\b/i', $line, $matches)) {
            $level = strtoupper($matches['level']);

            if (! in_array($level, $levels, true)) {
                return null;
            }

            return [
                'time' => null,
                'level' => $level,
                'summary' => mb_strimwidth($line, 0, 180, '...'),
                'rule' => 'keyword-level',
            ];
        }

        if (str_contains($line, 'Exception') || str_contains($line, 'not found') || str_contains($line, 'failed')) {
            return [
                'time' => null,
                'level' => 'ERROR',
                'summary' => mb_strimwidth($line, 0, 180, '...'),
                'rule' => 'exception-keyword',
            ];
        }

        return null;
    }

    private function buildEvent(string $source, array $raw): array
    {
        $lines = array_slice($raw['lines'], 0, max(1, (int) config('ops.logs.error_watcher.context_lines', 3)));
        $snippet = $this->sanitizeText(implode(PHP_EOL, $lines));
        $event = [
            'source' => $source,
            'level' => strtoupper((string) $raw['level']),
            'severity' => $this->severity((string) $raw['level'], (string) $raw['summary']),
            'time' => $raw['time'],
            'summary' => $this->sanitizeText((string) $raw['summary']),
            'snippet' => mb_strimwidth($snippet, 0, 1000, '...'),
            'line_count' => count($raw['lines']),
            'rule' => $raw['rule'],
            'suggestion' => $this->suggestion((string) $raw['summary'], $snippet),
        ];
        $event['fingerprint'] = $this->fingerprint($event);

        return $event;
    }

    private function coalesceBuildEvents(array $events): array
    {
        $coalesced = [];

        foreach ($events as $event) {
            $lastIndex = count($coalesced) - 1;
            $isDockerCopy = str_contains($event['snippet'], 'failed to calculate checksum')
                || str_contains($event['snippet'], 'COPY');

            if ($event['source'] === 'build' && $isDockerCopy && $lastIndex >= 0) {
                $coalesced[$lastIndex]['snippet'] = mb_strimwidth(
                    $coalesced[$lastIndex]['snippet'].PHP_EOL.$event['snippet'],
                    0,
                    1000,
                    '...',
                );
                $coalesced[$lastIndex]['line_count'] += $event['line_count'];
                $coalesced[$lastIndex]['suggestion'] = $this->suggestion($coalesced[$lastIndex]['summary'], $coalesced[$lastIndex]['snippet']);
                $coalesced[$lastIndex]['fingerprint'] = $this->fingerprint($coalesced[$lastIndex]);

                continue;
            }

            $coalesced[] = $event;
        }

        return $coalesced;
    }

    private function storeAlert(array $event): OpsAlert
    {
        $alert = OpsAlert::query()->firstOrNew([
            'fingerprint' => $event['fingerprint'],
        ]);
        $shouldNotify = ! $alert->exists;

        $alert->fill([
            'fingerprint' => $event['fingerprint'],
            'source' => 'logs:'.$event['source'],
            'severity' => $event['severity'],
            'title' => '日志报错：'.$event['source'].' '.$event['level'],
            'message' => $event['summary'].' 建议：'.$event['suggestion'],
            'context' => [
                'source' => $event['source'],
                'level' => $event['level'],
                'time' => $event['time'],
                'summary' => $event['summary'],
                'snippet' => $event['snippet'],
                'rule' => $event['rule'],
                'suggestion' => $event['suggestion'],
            ],
            'status' => 'open',
            'last_seen_at' => now(),
            'hit_count' => $alert->exists ? $alert->hit_count + 1 : 1,
        ]);
        $alert->save();

        if ($shouldNotify) {
            try {
                $notification = $this->notification->send($alert);
                $this->recordNotificationResult($alert, $notification);
            } catch (Throwable $e) {
                $this->recordNotificationResult($alert, [
                    'error' => [
                        'sent' => false,
                        'reason' => 'notification_exception',
                        'message' => $this->sanitizeText($e->getMessage()),
                    ],
                ]);
            }
        }

        try {
            broadcast(new AlertTriggered($alert));
        } catch (Throwable) {
            // 告警已经落库；广播失败不能阻断日志扫描命令。
        }

        return $alert;
    }

    private function recordNotificationResult(OpsAlert $alert, array $result): void
    {
        $failed = collect($result)
            ->first(fn (array $channel): bool => ($channel['sent'] ?? true) === false
                && isset($channel['reason'])
                && ! in_array($channel['reason'], ['channel_disabled_by_policy', 'telegram_not_configured', 'mail_recipient_empty'], true));

        if ($failed === null) {
            return;
        }

        $context = (array) $alert->context;
        $context['notification_failed'] = true;
        $context['notification_reason'] = (string) ($failed['reason'] ?? 'notification_failed');
        $alert->context = $context;
        $alert->save();
    }

    private function severity(string $level, string $summary): string
    {
        $upper = strtoupper($level.' '.$summary);

        if (str_contains($upper, 'EMERGENCY') || str_contains($upper, 'CRITICAL')) {
            return 'critical';
        }

        return 'warning';
    }

    private function suggestion(string $summary, string $snippet): string
    {
        $text = $summary.PHP_EOL.$snippet;

        return match (true) {
            str_contains($text, 'fputcsv()') => '检查 CSV 导出，显式传入 fputcsv escape 参数。',
            str_contains($text, 'Log [deprecations] is not defined') => '检查 logging 配置，确保 deprecations channel 已定义。',
            str_contains($text, 'iostat: not found') => '检查高级系统监控，缺少 iostat 时应走可用性兜底。',
            str_contains($text, 'failed to calculate checksum') || str_contains($text, '"/start-container": not found') => '检查 Docker build context，使用 docker compose build laravel.test 或 docker build -f docker/8.4/Dockerfile docker/8.4。',
            str_contains($text, 'SQLSTATE[42S02]') => '检查数据库迁移是否已执行，缺失表需要运行 artisan migrate。',
            str_contains($text, 'Connection refused') && str_contains($text, 'mysql') => '检查 MySQL 容器或数据库连接配置。',
            str_contains($text, 'Connection refused') && str_contains($text, '6001') => '检查 Reverb/Pusher 服务是否启动。',
            default => '检索日志 fingerprint 和摘要，定位对应服务、配置或最近发布变更。',
        };
    }

    private function sanitizeText(string $text): string
    {
        $text = preg_replace('/(token|password|secret|api[_-]?key|auth_signature)=([^&\s"]+)/i', '$1=[FILTERED]', $text) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [FILTERED]', $text) ?? $text;

        return $text;
    }

    private function normalizeFingerprintText(string $text): string
    {
        $text = preg_replace('/\d+/', '#', $text) ?? $text;

        return mb_strtolower($text);
    }

    private function fingerprintText(array $event): string
    {
        $summary = (string) ($event['summary'] ?? '');
        $snippet = (string) ($event['snippet'] ?? '');
        $canonical = $this->databaseMissingTableFingerprintText($summary.PHP_EOL.$snippet);

        if ($canonical !== null) {
            return $canonical;
        }

        return $this->normalizeFingerprintText($summary);
    }

    private function databaseMissingTableFingerprintText(string $text): ?string
    {
        if (! str_contains($text, 'SQLSTATE[42S02]')) {
            return null;
        }

        if (! preg_match("/Table ['\"](?<table>[^'\"]+)['\"] doesn't exist/i", $text, $matches)) {
            return null;
        }

        return 'sqlstate-42s02-missing-table:'.mb_strtolower((string) $matches['table']);
    }

    private function isStackContinuation(string $source, string $line): bool
    {
        if (! in_array($source, ['laravel', 'worker', 'scheduler', 'octane'], true)) {
            return false;
        }

        if (preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+[A-Za-z0-9_-]+\.[A-Za-z]+:/', $line)) {
            return false;
        }

        return preg_match('/^(#\d+\s|Stack trace:|Next |Caused by:|\[stacktrace\]|\[previous exception\]|\d+▕|\{main\})/i', $line) === 1
            || str_contains($line, 'Exception')
            || str_contains($line, 'SQLSTATE[')
            || str_contains($line, '/var/www/html/vendor/')
            || str_contains($line, '/var/www/html/app/');
    }

    private function readState(): array
    {
        $file = (string) config('ops.logs.error_watcher.state_file');

        if (! is_file($file) || ! is_readable($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void
    {
        $file = (string) config('ops.logs.error_watcher.state_file');
        $directory = dirname($file);

        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function filePrefixHash(string $file): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        $prefix = fread($handle, 512);
        fclose($handle);

        return sha1(is_string($prefix) ? $prefix : '');
    }
}
