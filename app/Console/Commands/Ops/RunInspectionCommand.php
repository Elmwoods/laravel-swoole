<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsInspectionService;
use Illuminate\Console\Command;

class RunInspectionCommand extends Command
{
    protected $signature = 'ops:inspections:run
        {--type=light : Inspection type, light or full}';

    protected $description = 'Run Ops Center internal inspection and persist history';

    public function handle(OpsInspectionService $service): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, ['light', 'full'], true)) {
            $this->error('Invalid inspection type.');

            return self::FAILURE;
        }

        $record = $service->run($type, 'schedule');

        $this->info(sprintf(
            'Ops inspection #%d finished with status=%s pass=%d warn=%d fail=%d',
            $record->id,
            $record->status,
            $record->summary['pass'] ?? 0,
            $record->summary['warn'] ?? 0,
            $record->summary['fail'] ?? 0,
        ));

        return $record->status === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
