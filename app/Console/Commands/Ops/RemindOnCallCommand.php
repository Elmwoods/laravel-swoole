<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OnCallRotationService;
use Illuminate\Console\Command;
use Throwable;

class RemindOnCallCommand extends Command
{
    protected $signature = 'ops:on-call:remind';

    protected $description = 'Remind on-call assignees shortly before their upcoming shift starts';

    public function handle(OnCallRotationService $service): int
    {
        try {
            $sent = $service->sendDueReminders();
            $this->info("值班上岗提醒推送 {$sent} 条。");
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('值班上岗提醒跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
