<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class SendAlertDigestCommand extends Command
{
    protected $signature = 'ops:alerts:digest
        {--hours= : 聚合窗口小时数，默认取 config，范围 1-168}
        {--dry-run : 只渲染并打印摘要正文，不发送}';

    protected $description = 'Aggregate recent alerts and push a single digest through configured channels';

    public function handle(AlertCenterService $service): int
    {
        try {
            $hours = $this->hoursOption() ?? (int) config('ops.alerts.digest.window_hours', 24);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $summary = $service->digestSummary($hours);
            $this->info("摘要预览（{$summary['total']} 条 / 窗口 {$summary['window_hours']}h，不发送）：");
            $this->line($service->renderDigest($summary));

            return self::SUCCESS;
        }

        try {
            $result = $service->sendDigest($hours);

            if (($result['sent'] ?? false) === true) {
                $total = $result['summary']['total'] ?? 0;
                $this->info("告警摘要已推送（{$total} 条）。");
            } else {
                $this->info('告警摘要未发送：'.($result['reason'] ?? 'unknown').'。');
            }
        } catch (Throwable $e) {
            // 容错：定时任务瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('告警摘要发送跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function hoursOption(): ?int
    {
        $value = $this->option('hours');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('聚合窗口小时数必须是数字。');
        }

        return (int) $value;
    }
}
