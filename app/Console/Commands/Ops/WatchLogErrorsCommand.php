<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Log\OpsLogErrorWatcherService;
use Illuminate\Console\Command;
use Throwable;

class WatchLogErrorsCommand extends Command
{
    protected $signature = 'ops:logs:watch-errors
        {--source=* : Whitelisted log source key to scan}
        {--once : Run one scan and exit}
        {--since-offset-reset : Ignore stored offsets and read selected files from the beginning}
        {--dry-run : Print diagnostics without writing Ops alerts}';

    protected $description = 'Scan whitelisted Ops logs for new errors and create lightweight diagnostic alerts';

    public function handle(OpsLogErrorWatcherService $watcher): int
    {
        $sources = (array) $this->option('source');
        $configured = $watcher->sources();

        foreach ($sources as $source) {
            if (! array_key_exists($source, $configured)) {
                $this->error("Invalid log source: {$source}");

                return self::FAILURE;
            }
        }

        try {
            $result = $watcher->scan(
                sources: $sources,
                dryRun: (bool) $this->option('dry-run'),
                resetOffsets: (bool) $this->option('since-offset-reset'),
            );
        } catch (Throwable $e) {
            // 扫描本身失败不让命令非零退出，否则调度器写 ERROR 又被本命令采集成新告警。
            $this->warn('Ops log error scan skipped: '.$e->getMessage());

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            foreach ($result['events'] as $event) {
                $this->line(sprintf(
                    'source=%s level=%s fingerprint=%s summary=%s',
                    $event['source'],
                    $event['level'],
                    $event['fingerprint'],
                    $event['summary'],
                ));
                $this->line('suggestion='.$event['suggestion']);
            }
        }

        $this->info(sprintf(
            'Scanned %d sources, detected %d new error events',
            $result['scanned'],
            $result['detected'],
        ));

        return self::SUCCESS;
    }
}
