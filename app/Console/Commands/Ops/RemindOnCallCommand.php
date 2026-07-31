<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OnCallRotationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 值班上岗提醒命令
 *
 * 命令 `ops:on-call:remind`（无参数），在值班人员即将上岗前向其推送上岗提醒。
 * 具体的“到点判定”与推送由 OnCallRotationService::sendDueReminders 负责，
 * 本命令仅作为调度入口，通常按较高频率（如每分钟/每几分钟）周期性触发，
 * 以便及时命中临近班次开始的时间窗口。
 *
 * 韧性设计：sendDueReminders 的瞬时失败被 catch 后仅 warn 提示并返回
 * SUCCESS，避免命令以非零退出使调度器写 ERROR 日志、进而被日志监控
 * （watch-errors）二次采集成新告警，形成噪声循环。
 */
class RemindOnCallCommand extends Command
{
    protected $signature = 'ops:on-call:remind';

    protected $description = 'Remind on-call assignees shortly before their upcoming shift starts';

    public function handle(OnCallRotationService $service): int
    {
        try {
            // 推送所有已到提醒时点的值班上岗提醒，返回实际发送条数
            $sent = $service->sendDueReminders();
            $this->info("值班上岗提醒推送 {$sent} 条。");
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('值班上岗提醒跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
