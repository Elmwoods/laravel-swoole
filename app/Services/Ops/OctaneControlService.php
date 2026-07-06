<?php

namespace App\Services\Ops;

use Symfony\Component\Process\Process;

/**
 * Octane Worker 控制与监控服务
 *
 * 该服务只负责采集和执行 Octane 运维动作，Controller 保持薄层。
 * 命令执行统一使用 Symfony Process，适配 Sail/Docker/Octane 生产环境。
 */
class OctaneControlService
{
    /**
     * 获取 Octane 运行状态与 Worker 数量。
     */
    public function status(): array
    {
        $processes = $this->processes();
        $masterPid = $this->masterPidFromStateFile();

        return [
            'running' => $masterPid !== null || count($processes) > 0,
            'server' => config('octane.server', 'swoole'),
            'configured_workers' => (int) config('octane.workers', 0),
            'configured_task_workers' => (int) config('octane.swoole.options.task_worker_num', config('octane.task_workers', 0)),
            'master_pid' => $masterPid,
            'process_count' => count($processes),
            'workers' => $processes,
            'state_file' => [
                'path' => $this->stateFile(),
                'exists' => is_file($this->stateFile()),
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 采集 Octane 相关进程。
     *
     * ps 输出字段固定为 pid/ppid/cpu/mem/运行时长/命令，避免依赖 grep 管道。
     */
    private function processes(): array
    {
        $process = new Process([
            'ps',
            'ax',
            '-o',
            'pid=,ppid=,pcpu=,pmem=,etime=,command=',
        ]);

        $process->setTimeout(3);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return collect(explode(PHP_EOL, trim($process->getOutput())))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): array => $this->parseProcessLine($line))
            ->filter(function (array $row): bool {
                $command = strtolower($row['command']);

                return str_contains($command, 'octane:start')
                    || str_contains($command, 'swoole')
                    || str_contains($command, 'roadrunner');
            })
            ->values()
            ->all();
    }

    /**
     * 解析单行 ps 输出。
     */
    private function parseProcessLine(string $line): array
    {
        $columns = preg_split('/\s+/', trim($line), 6);

        return [
            'pid' => (int) ($columns[0] ?? 0),
            'parent_pid' => (int) ($columns[1] ?? 0),
            'cpu_percent' => (float) ($columns[2] ?? 0),
            'memory_percent' => (float) ($columns[3] ?? 0),
            'running_time' => $columns[4] ?? '',
            'command' => $columns[5] ?? '',
        ];
    }

    /**
     * Octane state 文件路径。
     */
    private function stateFile(): string
    {
        return storage_path('logs/octane-server-state.json');
    }

    /**
     * 从 Octane state 文件读取 Master PID。
     */
    private function masterPidFromStateFile(): ?int
    {
        if (! is_file($this->stateFile())) {
            return null;
        }

        $state = json_decode((string) file_get_contents($this->stateFile()), true);

        if (! is_array($state)) {
            return null;
        }

        return isset($state['masterProcessId']) ? (int) $state['masterProcessId'] : null;
    }

    /**
     * Reload Octane Worker（优雅重载）。
     *
     * 在 Sail + Supervisor + Swoole 场景里，Octane master 可能由 root/supervisor
     * 启动，而 `sail artisan octane:reload` 以项目用户执行时会因为没有权限给
     * master PID 发信号而失败。这里优先交给 supervisorctl 重启 octane program，
     * 让 Supervisor 负责停止旧进程并拉起新进程。
     */
    public function reload(): bool
    {
        return $this->restartBySupervisor()
            || $this->runArtisanOctaneCommand('octane:reload');
    }

    /**
     * Stop Octane。
     */
    public function stop(): bool
    {
        return $this->runSupervisorCommand(['supervisorctl', 'stop', 'octane'])
            || $this->runArtisanOctaneCommand('octane:stop');
    }

    /**
     * Restart Octane。
     *
     * Docker + Supervisor 环境中直接重启 octane program，比在 Web 请求里
     * stop/start 常驻进程更可控，也能避开 Swoole PID 权限问题。
     */
    public function restart(): bool
    {
        return $this->restartBySupervisor();
    }

    /**
     * 通过 Supervisor 重启 Octane。
     */
    private function restartBySupervisor(): bool
    {
        return $this->runSupervisorCommand([
            'supervisorctl',
            'restart',
            'octane',
        ]);
    }

    /**
     * 执行 supervisorctl 命令。
     */
    private function runSupervisorCommand(array $command): bool
    {
        $process = new Process($command, base_path());
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            logger()->warning('Octane supervisor command failed', [
                'command' => $command,
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ]);
        }

        return $process->isSuccessful();
    }

    /**
     * 执行 Laravel Octane 原生命令，作为非 Supervisor 环境的兜底。
     */
    private function runArtisanOctaneCommand(string $command): bool
    {
        $process = new Process(['php', 'artisan', $command], base_path());
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            logger()->warning('Octane artisan command failed', [
                'command' => $command,
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ]);
        }

        return $process->isSuccessful();
    }
}
