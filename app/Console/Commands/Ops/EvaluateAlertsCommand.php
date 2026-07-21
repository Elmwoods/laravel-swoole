<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ops Center 告警评估命令。
 */
class EvaluateAlertsCommand extends Command
{
    protected $signature = 'ops:alerts:evaluate';

    protected $description = 'Evaluate Ops Center alert rules and broadcast lightweight alerts';

    /**
     * 执行告警评估。
     *
     * 瞬时采集失败不让命令以非零退出：失败已由 evaluate() 内部 recordEvaluation 记录，
     * 若这里抛出，调度器会写 ERROR 日志并被 watch-errors 采集成新告警，形成自澎环。
     */
    public function handle(AlertCenterService $service): int
    {
        try {
            $result = $service->evaluate('cli');
            $this->info("Ops alerts evaluated, detected: {$result['detected']}");
        } catch (Throwable $e) {
            $this->warn('Ops alert evaluation skipped: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
