<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertChannelHealthService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 定时对已启用通知通道做连通性自检，连续失败超阈值升 channel_health 告警。
 *
 * 容错：瞬时失败以退出码 0 结束，避免调度器 ERROR 被日志监控采成新告警。
 */
class CheckChannelHealthCommand extends Command
{
    protected $signature = 'ops:alerts:health-check {--dry-run : 只探测统计，不落库/不发告警}';

    protected $description = 'Probe each enabled notification channel and raise an alert on persistent failure';

    public function handle(AlertChannelHealthService $service): int
    {
        try {
            // dry-run 传给服务层：只探测统计，不落库、不发/不恢复告警
            $result = $service->run((bool) $this->option('dry-run'));

            // 配置未开启通道健康自检时，服务层返回 enabled=false，命令直接跳过
            if (($result['enabled'] ?? false) === false) {
                $this->info('通道健康自检未启用，跳过。');
            } else {
                $this->info("通道健康自检完成：探测 {$result['probed']}｜正常 {$result['healthy']}｜异常 {$result['failing']}｜升警 {$result['raised']}｜恢复 {$result['resolved']}。");
            }
        } catch (Throwable $e) {
            // 容错：探测异常只 warn 不抛出，避免调度器写 ERROR 被日志监控采成新告警
            $this->warn('通道健康自检跳过：'.$e->getMessage());
        }

        // 无论成功或跳过都返回退出码 0（SUCCESS），确保调度器不记 ERROR
        return self::SUCCESS;
    }
}
