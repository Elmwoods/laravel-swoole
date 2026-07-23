<?php

namespace App\Console\Commands\Admin;

use App\Services\Admin\AdminIpAutoBanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 定时扫描失败登录暴增的来源 IP，自动写临时 deny 规则。
 *
 * 容错：瞬时失败以退出码 0 结束，避免调度器 ERROR 被日志监控采成新告警（同其它定时 ops 命令）。
 */
class ScanIpAutoBanCommand extends Command
{
    protected $signature = 'admin:ip-auto-ban
        {--dry-run : 只统计不封禁/不推进游标/不清理}
        {--reset-cursor : 重置扫描游标}';

    protected $description = 'Scan failed-login bursts per source IP and auto-ban abusive IPs (temporary deny rules)';

    public function handle(AdminIpAutoBanService $service): int
    {
        if ($this->option('reset-cursor')) {
            $service->resetCursor();
            $this->info('自动封禁游标已重置。');
        }

        try {
            $result = $service->scan((bool) $this->option('dry-run'));

            if (($result['enabled'] ?? false) === false) {
                $this->info('自动封禁未启用，跳过。');
            } elseif (($result['initialized'] ?? false) === true) {
                $this->info("首次运行，游标初始化到 id={$result['last_id']}，不封禁历史。");
            } else {
                $banned = $result['banned'] ?? 0;
                $released = $result['released'] ?? 0;
                $scanned = $result['scanned'] ?? 0;
                $this->info("自动封禁扫描完成：扫描 {$scanned} 行，新封禁 {$banned} 个 IP，释放到期 {$released} 条。");
            }
        } catch (Throwable $e) {
            $this->warn('自动封禁扫描跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
