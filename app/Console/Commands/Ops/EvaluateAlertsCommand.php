<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ops Center 告警评估命令。
 *
 * 作用：拉取一轮各项监控指标，逐条比对已配置的告警规则，命中即广播轻量告警。
 * 核心逻辑在 AlertCenterService::evaluate('cli')，来源标记为 cli 表示由命令行/调度触发。
 *
 * $signature：无选项、无参数，纯周期性执行。
 * 调度：作为高频定时任务运行（分钟级），是告警体系的主评估入口。
 *
 * 容错：瞬时采集失败不让命令以非零退出——失败已由 evaluate() 内部 recordEvaluation 记录；
 * 若在此抛出，调度器会写 ERROR 日志并被 watch-errors 采集成新告警，形成自激环。
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
            // 'cli' 标记本轮评估的触发来源，detected 为本轮命中的告警条数
            $result = $service->evaluate('cli');
            $this->info("Ops alerts evaluated, detected: {$result['detected']}");
        } catch (Throwable $e) {
            // 容错：只 warn 不抛出，避免调度器 ERROR 被 watch-errors 采成新告警
            $this->warn('Ops alert evaluation skipped: '.$e->getMessage());
        }

        // 始终返回 SUCCESS，让调度器不记 ERROR
        return self::SUCCESS;
    }
}
