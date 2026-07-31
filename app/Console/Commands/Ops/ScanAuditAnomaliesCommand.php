<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AuditAnomalyScanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 后台审计日志异常扫描命令
 *
 * 命令 `ops:audit:scan-anomalies`，扫描管理端审计日志，识别异常行为
 * （如登录失败暴增、敏感操作）并据此产生告警，扫描逻辑由
 * AuditAnomalyScanService 承担。服务内部维护一个“游标（cursor）”
 * 记录上次已扫描到的最大审计日志 id，实现增量扫描、避免重复处理。
 *
 * $signature 选项：
 *  --dry-run      ：只统计不发送告警、也不推进游标（用于演练/验证）。
 *  --reset-cursor ：重置游标，使下次运行从当前最大 id 重新初始化。
 *
 * 通常按调度周期高频运行。首次运行（或重置后）仅将游标初始化到当前最大
 * id，本次不告警，以免把历史存量日志一次性判定为异常。
 *
 * 韧性设计：scan 的瞬时失败被 catch 后仅 warn 并返回 SUCCESS，避免命令
 * 非零退出使调度器写 ERROR 日志、被日志监控二次采集成新告警。
 */
class ScanAuditAnomaliesCommand extends Command
{
    protected $signature = 'ops:audit:scan-anomalies
        {--dry-run : 只统计不发送/不推进游标}
        {--reset-cursor : 重置游标（下次从当前最大 id 重新初始化）}';

    protected $description = 'Scan admin audit logs for anomalies (failed-login bursts, sensitive actions) and raise alerts';

    public function handle(AuditAnomalyScanService $service): int
    {
        // --reset-cursor：先重置增量扫描游标，使随后扫描从当前最大 id 起重新初始化
        if ($this->option('reset-cursor')) {
            $service->resetCursor();
            $this->info('审计异常检测游标已重置。');
        }

        try {
            // 执行增量扫描；dry-run 下只统计、不发送告警也不推进游标
            $result = $service->scan((bool) $this->option('dry-run'));

            if (($result['enabled'] ?? false) === false) {
                // 配置层未启用审计异常检测，直接跳过
                $this->info('审计异常检测未启用。');
            } elseif ($result['initialized'] ?? false) {
                // 首次运行：只初始化游标到当前最大 id，本次不产生告警
                $this->info("首次运行，游标初始化到 #{$result['last_id']}，本次不告警。");
            } else {
                // 正常增量扫描：输出本轮扫描条数及命中的敏感操作 / 失败登录暴增数量
                $suffix = $this->option('dry-run') ? '（dry-run，未发送）' : '';
                $this->info("扫描 {$result['scanned']} 条：敏感操作 {$result['sensitive']}、失败登录暴增 {$result['bursts']}{$suffix}。");
            }
        } catch (Throwable $e) {
            // 容错：瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('审计异常扫描跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
