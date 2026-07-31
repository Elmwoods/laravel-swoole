<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ops Center 告警升级命令。
 *
 * 作用：把处于 open 且长时间未被确认（ack）超过阈值的 critical 告警重新推送一次（re-notify），
 * 防止关键告警被长期忽略。判定与重推逻辑由 AlertCenterService::escalateStaleAlerts 负责。
 *
 * $signature 选项：
 *   --dry-run  只统计将要升级的告警条数，不重推、不打升级标记，用于预演。
 *
 * 调度：随告警调度节奏周期性执行（与 evaluate 一类的定时任务同一档）。
 *
 * 容错：瞬时失败以 warn 记录并返回退出码 0（SUCCESS），避免调度器写 ERROR
 * 被日志监控 watch-errors 再采集成新告警，形成自激环。
 */
class EscalateAlertsCommand extends Command
{
    protected $signature = 'ops:alerts:escalate {--dry-run : 只统计将升级的告警，不重推/不标记}';

    protected $description = 'Escalate open critical alerts left unacknowledged past the threshold (re-notify)';

    public function handle(AlertCenterService $service): int
    {
        try {
            // dry-run 传给服务层：只挑出待升级告警，不实际重推/不标记
            $escalated = $service->escalateStaleAlerts((bool) $this->option('dry-run'));
            // dry-run 时在输出上追加提示，说明本次并未真正重推
            $suffix = $this->option('dry-run') ? '（dry-run，未重推）' : '';
            $this->info("升级未确认 critical 告警 {$escalated->count()} 条{$suffix}。");
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('告警升级跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
