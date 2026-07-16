<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Log\OpsLogErrorWatcherService;
use Illuminate\Console\Command;

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

        $result = $watcher->scan(
            sources: $sources,
            dryRun: (bool) $this->option('dry-run'),
            resetOffsets: (bool) $this->option('since-offset-reset'),
        );

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
