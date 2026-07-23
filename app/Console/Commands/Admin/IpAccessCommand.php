<?php

namespace App\Console\Commands\Admin;

use App\Models\AdminIpRule;
use App\Services\Admin\AdminIpAccessService;
use Illuminate\Console\Command;

/**
 * Break-glass 管理后台登录 IP 准入名单。
 *
 * 当管理员把自己的 IP 锁在名单之外时，可从服务器 CLI 紧急关闭 / 清空 / 切换模式，
 * 无需登录后台即可自救。
 */
class IpAccessCommand extends Command
{
    protected $signature = 'admin:ip-access
        {--status : 打印当前启用状态、模式与规则计数}
        {--disable : 紧急关闭 IP 准入（放行全部）}
        {--flush : 清空所有 IP 规则}
        {--allowlist : 切换为白名单模式}
        {--blocklist : 切换为黑名单模式}';

    protected $description = 'Break-glass management of admin login IP allow/deny rules';

    public function handle(AdminIpAccessService $ipAccess): int
    {
        $acted = false;

        if ($this->option('disable')) {
            $ipAccess->updateSettings(['ip_access_enabled' => false]);
            $this->info('已关闭后台登录 IP 准入（当前放行全部来源）。');
            $acted = true;
        }

        if ($this->option('allowlist')) {
            $ipAccess->updateSettings(['ip_access_mode' => 'allowlist']);
            $this->info('已切换为白名单模式。');
            $acted = true;
        }

        if ($this->option('blocklist')) {
            $ipAccess->updateSettings(['ip_access_mode' => 'blocklist']);
            $this->info('已切换为黑名单模式。');
            $acted = true;
        }

        if ($this->option('flush')) {
            $deleted = AdminIpRule::query()->delete();
            $ipAccess->flushCache();
            $this->info("已清空 {$deleted} 条 IP 规则。");
            $acted = true;
        }

        // 无任何动作，或显式 --status：打印当前状态。
        if (! $acted || $this->option('status')) {
            $this->printStatus($ipAccess);
        }

        return self::SUCCESS;
    }

    private function printStatus(AdminIpAccessService $ipAccess): void
    {
        $settings = $ipAccess->settings();
        $allow = AdminIpRule::query()->where('type', 'allow')->where('is_active', true)->count();
        $deny = AdminIpRule::query()->where('type', 'deny')->where('is_active', true)->count();

        $enabled = ($settings['ip_access_enabled'] ?? false) ? '启用' : '关闭';
        $mode = $settings['ip_access_mode'] ?? 'blocklist';

        $this->line("IP 准入：{$enabled}｜模式：{$mode}｜有效 allow 规则 {$allow} 条｜有效 deny 规则 {$deny} 条");
    }
}
