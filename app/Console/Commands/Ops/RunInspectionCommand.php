<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsInspectionService;
use Illuminate\Console\Command;
use Throwable;

class RunInspectionCommand extends Command
{
    protected $signature = 'ops:inspections:run
        {--type=light : Inspection type, light or full}';

    protected $description = 'Run Ops Center internal inspection and persist history';

    /**
     * 一次完成的巡检即视为命令成功，即使巡检结果是 fail。
     *
     * fail 结果已通过 OpsInspectionService::run 内的 raiseInspectionAlert 上报，
     * 无需再用退出码二次信号；否则调度器会写 ERROR 日志并被 watch-errors 采集成新告警。
     * 仅非法参数或 run() 抛异常时才不算正常完成。
     */
    public function handle(OpsInspectionService $service): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, ['light', 'full'], true)) {
            $this->error('Invalid inspection type.');

            return self::FAILURE;
        }

        try {
            $record = $service->run($type, 'schedule');
        } catch (Throwable $e) {
            $this->warn('Ops inspection skipped: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Ops inspection #%d finished with status=%s pass=%d warn=%d fail=%d',
            $record->id,
            $record->status,
            $record->summary['pass'] ?? 0,
            $record->summary['warn'] ?? 0,
            $record->summary['fail'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
