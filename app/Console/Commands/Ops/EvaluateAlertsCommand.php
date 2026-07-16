<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;

/**
 * Ops Center 告警评估命令。
 */
class EvaluateAlertsCommand extends Command
{
    protected $signature = 'ops:alerts:evaluate';

    protected $description = 'Evaluate Ops Center alert rules and broadcast lightweight alerts';

    /**
     * 执行告警评估。
     */
    public function handle(AlertCenterService $service): int
    {
        $result = $service->evaluate('cli');

        $this->info("Ops alerts evaluated, detected: {$result['detected']}");

        return self::SUCCESS;
    }
}
