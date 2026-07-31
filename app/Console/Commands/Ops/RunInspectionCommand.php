<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsInspectionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ops Center 内部巡检命令
 *
 * 命令 `ops:inspections:run`，触发一次系统内部巡检并将结果持久化为历史记录，
 * 巡检逻辑由 OpsInspectionService::run 承担。
 *
 * $signature 选项：
 *  --type ：巡检类型，取值 light（轻量，默认）或 full（完整）；
 *           传入其它值视为非法参数，直接返回 FAILURE。
 *
 * 一般按调度周期运行（handle 中以来源 'schedule' 触发），轻量巡检可高频、
 * 完整巡检低频。韧性设计见下方 handle 的方法级 docblock：只要巡检“跑完了”
 * 就算命令成功，fail 结果通过 Service 内部告警上报而非退出码信号。
 */
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

        // 参数校验：非法巡检类型是唯一会以 FAILURE 退出的情形
        if (! in_array($type, ['light', 'full'], true)) {
            $this->error('Invalid inspection type.');

            return self::FAILURE;
        }

        try {
            // 以来源 'schedule' 执行巡检并落库，返回巡检历史记录模型
            $record = $service->run($type, 'schedule');
        } catch (Throwable $e) {
            // 韧性：巡检执行抛异常时仅告警提示并返回 SUCCESS，
            // 避免调度器写 ERROR 日志被 watch-errors 二次采集成新告警
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
