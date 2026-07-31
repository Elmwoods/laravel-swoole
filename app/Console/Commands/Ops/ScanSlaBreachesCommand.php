<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 告警 SLA 违约扫描命令
 *
 * 命令 `ops:alerts:sla-scan`，扫描尚未处理的告警，对已超过其
 * 确认（ack）/ 解决（resolve）SLA 时限的告警升级出 sla_breach 告警，
 * 判定与升警逻辑由 AlertCenterService::scanSlaBreaches 承担。
 *
 * $signature 选项：
 *  --dry-run ：只统计即将/已经违约的告警数量，不实际升级 sla_breach。
 *
 * 通常按调度周期运行，以持续巡检未闭环告警的 SLA 达标情况。
 *
 * 韧性设计：扫描的瞬时失败被 catch 后仅 warn 并返回 SUCCESS，避免命令
 * 非零退出使调度器写 ERROR 日志、被日志监控二次采集成新告警。
 */
class ScanSlaBreachesCommand extends Command
{
    protected $signature = 'ops:alerts:sla-scan {--dry-run : 只统计将违约的告警，不升 sla_breach}';

    protected $description = 'Scan unresolved alerts and raise sla_breach alerts for those past their ack/resolve SLA targets';

    public function handle(AlertCenterService $service): int
    {
        try {
            // 扫描并对超时告警升级 sla_breach；dry-run 下只统计不升警
            $breached = $service->scanSlaBreaches((bool) $this->option('dry-run'));
            $suffix = $this->option('dry-run') ? '（dry-run，未升警）' : '';
            $this->info("SLA 违约告警 {$breached->count()} 条{$suffix}。");
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('SLA 扫描跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
