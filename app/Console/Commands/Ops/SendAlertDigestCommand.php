<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\AlertCenterService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * 告警摘要（digest）推送命令
 *
 * 命令 `ops:alerts:digest`，把最近一段时间窗口内的告警聚合成单条摘要，
 * 通过已配置的渠道统一推送，聚合与推送由 AlertCenterService 承担。
 * 用摘要合并替代逐条告警轰炸，降低通知噪声。
 *
 * $signature 选项：
 *  --hours   ：聚合窗口小时数，缺省取 config ops.alerts.digest.window_hours
 *              （默认 24），有效范围 1-168（见 hoursOption 校验，非数字即报错）。
 *  --dry-run ：只渲染并打印摘要正文，不实际发送。
 *
 * 通常按调度周期运行（例如每日一次）。韧性设计：真正发送阶段的瞬时失败被
 * catch 后仅 warn 并返回 SUCCESS，避免命令非零退出被日志监控二次采集成
 * 新告警；但参数非法（--hours 非数字）会提前返回 FAILURE。
 */
class SendAlertDigestCommand extends Command
{
    protected $signature = 'ops:alerts:digest
        {--hours= : 聚合窗口小时数，默认取 config，范围 1-168}
        {--dry-run : 只渲染并打印摘要正文，不发送}';

    protected $description = 'Aggregate recent alerts and push a single digest through configured channels';

    public function handle(AlertCenterService $service): int
    {
        try {
            // 解析聚合窗口：优先取 --hours，否则回落到 config 默认值；
            // --hours 非数字时 hoursOption 抛异常，走下方 FAILURE 分支
            $hours = $this->hoursOption() ?? (int) config('ops.alerts.digest.window_hours', 24);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // --dry-run：只按窗口生成摘要并打印预览，不走发送流程
        if ($this->option('dry-run')) {
            $summary = $service->digestSummary($hours);
            $this->info("摘要预览（{$summary['total']} 条 / 窗口 {$summary['window_hours']}h，不发送）：");
            $this->line($service->renderDigest($summary));

            return self::SUCCESS;
        }

        try {
            // 聚合窗口内告警并实际推送；返回 sent 标志与聚合汇总
            $result = $service->sendDigest($hours);

            if (($result['sent'] ?? false) === true) {
                $total = $result['summary']['total'] ?? 0;
                $this->info("告警摘要已推送（{$total} 条）。");
            } else {
                // 未发送（如窗口内无告警等），reason 说明具体原因
                $this->info('告警摘要未发送：'.($result['reason'] ?? 'unknown').'。');
            }
        } catch (Throwable $e) {
            // 容错：定时任务瞬时失败不以非零退出，避免调度器 ERROR 被日志监控再采集成告警。
            $this->warn('告警摘要发送跳过：'.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * 解析 --hours 选项：未提供（null 或空串）返回 null 以便回落 config；
     * 提供了非数字值则抛 InvalidArgumentException 交由 handle 转为 FAILURE。
     */
    private function hoursOption(): ?int
    {
        $value = $this->option('hours');

        // 未显式传入 --hours，返回 null 让调用方使用 config 默认窗口
        if ($value === null || $value === '') {
            return null;
        }

        // 传入了非数字值：视为非法参数
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('聚合窗口小时数必须是数字。');
        }

        return (int) $value;
    }
}
