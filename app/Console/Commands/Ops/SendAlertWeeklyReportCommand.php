<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use Throwable;

class SendAlertWeeklyReportCommand extends Command
{
    protected $signature = 'ops:alerts:weekly-report {--days= : 统计窗口天数，默认取 config} {--dry-run : 只打印周报正文，不推送}';

    protected $description = 'Aggregate a weekly alert/SLA/on-call report and push it via configured channels';

    public function handle(AlertCenterService $service): int
    {
        $days = $this->option('days') !== null
            ? max(1, min(90, (int) $this->option('days')))
            : (int) config('ops.alerts.weekly_report.window_days', 7);

        try {
            if ($this->option('dry-run')) {
                $report = $service->weeklyReportSummary($days);
                $this->line($service->renderWeeklyReport($report));

                return self::SUCCESS;
            }

            $result = $service->sendWeeklyReport($days);
            $this->info('告警周报：'.($result['sent'] ? '已推送。' : "未推送（{$result['reason']}）。"));
        } catch (Throwable $e) {
            $this->warn('告警周报跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
