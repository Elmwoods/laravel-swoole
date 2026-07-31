<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Log\OpsLogErrorWatcherService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ops 日志错误监控命令
 *
 * 命令 `ops:logs:watch-errors`，扫描白名单内的 Ops 日志源，发现新的错误
 * 后创建轻量诊断告警。实际扫描由 OpsLogErrorWatcherService 承担，服务内部
 * 为每个日志文件记录读取偏移量（offset），实现增量扫描、只处理新增内容。
 *
 * $signature 选项：
 *  --source              ：可多次指定，限定要扫描的白名单日志源 key；
 *                          传入未在白名单的 key 会返回 FAILURE。
 *  --once                ：只扫描一轮即退出。
 *  --since-offset-reset  ：忽略已存偏移量，从文件开头重新读取所选日志。
 *  --dry-run             ：只打印诊断信息（命中的错误事件），不写入 Ops 告警。
 *
 * 通常按调度周期运行。韧性设计尤为关键：本命令自身正是“日志错误 → 告警”
 * 链路的采集端，因此扫描失败时只 warn 并返回 SUCCESS——若非零退出，调度器
 * 会写 ERROR 日志，而该日志又会被本命令下一轮采集成新告警，形成自激循环。
 */
class WatchLogErrorsCommand extends Command
{
    protected $signature = 'ops:logs:watch-errors
        {--source=* : Whitelisted log source key to scan}
        {--once : Run one scan and exit}
        {--since-offset-reset : Ignore stored offsets and read selected files from the beginning}
        {--dry-run : Print diagnostics without writing Ops alerts}';

    protected $description = 'Scan whitelisted Ops logs for new errors and create lightweight diagnostic alerts';

    public function handle(OpsLogErrorWatcherService $watcher): int
    {
        $sources = (array) $this->option('source');
        $configured = $watcher->sources();

        // 校验所有 --source 均在服务的白名单内，任一非法即拒绝执行
        foreach ($sources as $source) {
            if (! array_key_exists($source, $configured)) {
                $this->error("Invalid log source: {$source}");

                return self::FAILURE;
            }
        }

        try {
            // 执行扫描：sources 为空表示扫描全部已配置源；
            // dryRun 不写告警；resetOffsets 从文件开头重新读取
            $result = $watcher->scan(
                sources: $sources,
                dryRun: (bool) $this->option('dry-run'),
                resetOffsets: (bool) $this->option('since-offset-reset'),
            );
        } catch (Throwable $e) {
            // 扫描本身失败不让命令非零退出，否则调度器写 ERROR 又被本命令采集成新告警。
            $this->warn('Ops log error scan skipped: '.$e->getMessage());

            return self::SUCCESS;
        }

        // --dry-run：逐条打印本轮命中的错误事件诊断（来源/级别/指纹/摘要/建议）
        if ((bool) $this->option('dry-run')) {
            foreach ($result['events'] as $event) {
                $this->line(sprintf(
                    'source=%s level=%s fingerprint=%s summary=%s',
                    $event['source'],
                    $event['level'],
                    $event['fingerprint'],
                    $event['summary'],
                ));
                $this->line('suggestion='.$event['suggestion']);
            }
        }

        $this->info(sprintf(
            'Scanned %d sources, detected %d new error events',
            $result['scanned'],
            $result['detected'],
        ));

        return self::SUCCESS;
    }
}
