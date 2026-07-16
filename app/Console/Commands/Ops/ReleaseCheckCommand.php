<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\OpsReleaseCheckService;
use Illuminate\Console\Command;

class ReleaseCheckCommand extends Command
{
    protected $signature = 'ops:release-check
        {--json : 输出机器可读 JSON}
        {--strict : 存在 fail 检查项时返回非 0}';

    protected $description = 'Run Ops Center release readiness checks';

    public function handle(OpsReleaseCheckService $service): int
    {
        $result = $service->run();

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

    private function exitCode(array $result): int
    {
        return $this->option('strict') && $result['status'] === 'fail'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
