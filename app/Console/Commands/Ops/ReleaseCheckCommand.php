<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsReleaseCheckService;
use Illuminate\Console\Command;

/**
 * Ops Center 发布就绪自检命令
 *
 * 命令 `ops:release-check`，对发布前的各项就绪条件做集中检查，
 * 由 OpsReleaseCheckService 执行并汇总为 pass/warn/fail 三类结果。
 *
 * $signature 选项：
 *  --json   ：以机器可读的 JSON 格式输出完整结果（供 CI/脚本消费），
 *             不带此选项则以人类可读的表格形式打印。
 *  --strict ：当存在 fail 检查项时以非 0 退出码结束（供 CI 卡点用）；
 *             默认（非 strict）无论结果如何都返回 SUCCESS。
 *
 * 通常在发布流水线中手动或按需触发，而非固定调度周期运行。
 */
class ReleaseCheckCommand extends Command
{
    protected $signature = 'ops:release-check
        {--json : 输出机器可读 JSON}
        {--strict : 存在 fail 检查项时返回非 0}';

    protected $description = 'Run Ops Center release readiness checks';

    public function handle(OpsReleaseCheckService $service): int
    {
        // 执行全部发布自检项，返回含 status/summary/checks 的结果数组
        $result = $service->run();

        // --json：直接输出格式化 JSON 供程序消费，随后按退出码约定返回
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $this->exitCode($result);
        }

        $this->info('Ops Center 发布自检');
        $this->line("总体状态：{$result['status']}");
        $this->line("汇总：pass={$result['summary']['pass']} warn={$result['summary']['warn']} fail={$result['summary']['fail']}");

        $this->table(
            ['分组', '检查项', '状态', '信息', '建议'],
            collect($result['checks'])
                ->map(fn (array $check): array => [
                    $check['group'],
                    $check['name'],
                    $check['status'],
                    $check['message'],
                    $check['hint'],
                ])
                ->all(),
        );

        return $this->exitCode($result);
    }

    /**
     * 计算命令退出码：仅当带 --strict 且整体状态为 fail 时返回 FAILURE，
     * 否则一律返回 SUCCESS（非 strict 下即便存在 fail 也视为正常完成）。
     */
    private function exitCode(array $result): int
    {
        return $this->option('strict') && $result['status'] === 'fail'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
