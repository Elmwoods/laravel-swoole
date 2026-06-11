<?php

namespace App\Services\Ops;

/**
 * Octane Worker 控制与监控
 */
class OctaneControlService
{
    /**
     * 获取 Octane 进程信息
     */
    public function status(): array
    {
        $output = shell_exec("ps aux | grep 'octane:start' | grep -v grep");

        return [
            'running' => !empty($output),
            'processes' => $this->parseProcesses($output),
        ];
    }

    /**
     * 解析进程信息
     */
    private function parseProcesses(?string $output): array
    {
        if (!$output) return [];

        $lines = explode("\n", trim($output));
        $result = [];

        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', $line);

            $result[] = [
                'user' => $cols[0] ?? null,
                'pid' => $cols[1] ?? null,
                'cpu' => $cols[2] ?? null,
                'mem' => $cols[3] ?? null,
                'command' => implode(' ', array_slice($cols, 10)),
            ];
        }

        return $result;
    }

    /**
     * Reload Octane（优雅重启）
     */
    public function reload(): bool
    {
        shell_exec("php artisan octane:reload");

        return true;
    }

    /**
     * Stop Octane
     */
    public function stop(): bool
    {
        shell_exec("pkill -f 'octane:start'");

        return true;
    }

    /**
     * Restart Octane
     */
    public function restart(): bool
    {
        $this->stop();
        sleep(1);

//        shell_exec("php artisan octane:start --host=0.0.0.0 --port=8080 --workers=4");

        return true;
    }
}
