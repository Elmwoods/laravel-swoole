<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 告警周报推送命令
 *
 * 命令 `ops:alerts:weekly-report`，把一个统计窗口内的告警 / SLA / 值班
 * 情况聚合成周报，通过已配置渠道推送，聚合与推送由 AlertCenterService 承担。
 *
 * $signature 选项：
 *  --days    ：统计窗口天数，缺省取 config ops.alerts.weekly_report.window_days
 *              （默认 7）；显式传入时会被夹取到 1-90 的范围内。
 *  --dry-run ：只渲染并打印周报正文，不实际推送。
 *
 * 通常按调度周期运行（例如每周一次）。韧性设计：聚合/推送的瞬时失败被
 * catch 后仅 warn 并返回 SUCCESS，避免命令非零退出被日志监控二次采集成
 * 新告警。
 */
class SendAlertWeeklyReportCommand extends Command
{
    protected $signature = 'ops:alerts:weekly-report {--days= : 统计窗口天数，默认取 config} {--dry-run : 只打印周报正文，不推送}';

    protected $description = 'Aggregate a weekly alert/SLA/on-call report and push it via configured channels';

    public function handle(AlertCenterService $service): int
    {
        // 统计窗口天数：显式传入则夹取到 [1,90]，否则回落 config 默认值
        $days = $this->option('days') !== null
            ? max(1, min(90, (int) $this->option('days')))
            : (int) config('ops.alerts.weekly_report.window_days', 7);

        try {
            // --dry-run：只生成并打印周报正文，不推送
            if ($this->option('dry-run')) {
                $report = $service->weeklyReportSummary($days);
                $this->line($service->renderWeeklyReport($report));

                return self::SUCCESS;
            }

            // 实际聚合并推送周报；sent 为 false 时 reason 说明未推送原因
            $result = $service->sendWeeklyReport($days);
            $this->info('告警周报：'.($result['sent'] ? '已推送。' : "未推送（{$result['reason']}）。"));
        } catch (Throwable $e) {
            $this->warn('告警周报跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
