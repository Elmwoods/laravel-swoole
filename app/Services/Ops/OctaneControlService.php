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
     * 作用：汇总 Octane 运行状态、配置的 worker 数量、master PID 及进程明细。
     *
     * 获取 Octane 运行状态与 Worker 数量。
     *
     * @return array 含 running/server/configured_workers/master_pid/workers/state_file 等
     *
     * 为什么：running 判定同时看 state 文件里的 master PID 与实际进程列表，
     * 二者任一存在即视为运行中，避免 state 文件残留或进程扫描漏判导致误报。
     */
    public function status(): array
    {
        $processes = $this->processes();
        $masterPid = $this->masterPidFromStateFile();

        return [
            'running' => $masterPid !== null || count($processes) > 0,
            // 读取配置的 Octane 服务器类型（默认 swoole）
            'server' => config('octane.server', 'swoole'),
            'configured_workers' => (int) config('octane.workers', 0),
            // task worker 数优先取 swoole 专属配置，回退到通用 task_workers
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
     * 作用：调用系统 ps 命令，筛选出 Octane / Swoole / RoadRunner 相关进程。
     *
     * 采集 Octane 相关进程。
     *
     * ps 输出字段固定为 pid/ppid/cpu/mem/运行时长/命令，避免依赖 grep 管道。
     *
     * @return array 匹配到的进程行数组；ps 执行失败时返回空数组
     *
     * 为什么：用 `-o 字段=` 指定无表头的固定列，直接在 PHP 内做过滤，
     * 比 `ps | grep` 管道更可控、跨平台更稳定，也不会误匹配到 grep 自身进程。
     */
    private function processes(): array
    {
        $process = new Process([
            'ps',
            'ax',
            '-o',
            'pid=,ppid=,pcpu=,pmem=,etime=,command=',
        ]);

        // 进程扫描应快速返回，超时上限 3 秒防止阻塞请求
        $process->setTimeout(3);
        $process->run();

        // ps 执行失败（权限/环境问题）时安全返回空列表，不抛异常
        if (! $process->isSuccessful()) {
            return [];
        }

        return collect(explode(PHP_EOL, trim($process->getOutput())))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): array => $this->parseProcessLine($line))
            ->filter(function (array $row): bool {
                // 命令统一转小写再做包含匹配
                $command = strtolower($row['command']);

                // 命中三类关键字即视为 Octane 相关进程（覆盖 swoole 与 roadrunner 两种 server）
                return str_contains($command, 'octane:start')
                    || str_contains($command, 'swoole')
                    || str_contains($command, 'roadrunner');
            })
            ->values()
            ->all();
    }

    /**
     * 作用：把一行 ps 输出按空白切成 6 段，解析为结构化进程信息。
     *
     * 解析单行 ps 输出。
     *
     * @param  string  $line  单行 ps 输出
     * @return array 含 pid/parent_pid/cpu_percent/memory_percent/running_time/command
     *
     * 为什么：limit 限定为 6，保证命令中的空格不会被继续拆分，command 字段能完整保留。
     */
    private function parseProcessLine(string $line): array
    {
        // 按连续空白切分，最多 6 段：最后一段 command 保留其内部空格
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
     * 作用：返回 Octane server state 文件的绝对路径。
     *
     * Octane state 文件路径。
     *
     * @return string state 文件绝对路径
     */
    private function stateFile(): string
    {
        return storage_path('logs/octane-server-state.json');
    }

    /**
     * 作用：从 Octane state 文件中读取 master 进程 PID。
     *
     * 从 Octane state 文件读取 Master PID。
     *
     * @return int|null master PID；文件不存在或内容非法时返回 null
     *
     * 为什么：每一步都做存在性/类型校验（文件在？是数组？含 masterProcessId？），
     * 使得 Octane 未启动或 state 文件损坏时函数安全返回 null 而非报错。
     */
    private function masterPidFromStateFile(): ?int
    {
        // 文件不存在说明 Octane 尚未启动，直接返回 null
        if (! is_file($this->stateFile())) {
            return null;
        }

        $state = json_decode((string) file_get_contents($this->stateFile()), true);

        // JSON 解析失败或结构异常时兜底
        if (! is_array($state)) {
            return null;
        }

        return isset($state['masterProcessId']) ? (int) $state['masterProcessId'] : null;
    }

    /**
     * 作用：优雅重载 Octane worker，优先走 Supervisor，失败再回退到 artisan 命令。
     *
     * Reload Octane Worker（优雅重载）。
     *
     * 在 Sail + Supervisor + Swoole 场景里，Octane master 可能由 root/supervisor
     * 启动，而 `sail artisan octane:reload` 以项目用户执行时会因为没有权限给
     * master PID 发信号而失败。这里优先交给 supervisorctl 重启 octane program，
     * 让 Supervisor 负责停止旧进程并拉起新进程。
     *
     * @return bool 任一路径成功即为 true
     *
     * 为什么：`||` 短路——Supervisor 成功就不再执行 artisan 兜底，兼顾生产（Supervisor）与本地（无 Supervisor）两种环境。
     */
    public function reload(): bool
    {
        return $this->restartBySupervisor()
            || $this->runArtisanOctaneCommand('octane:reload');
    }

    /**
     * 作用：停止 Octane，优先 supervisorctl stop，失败回退 artisan octane:stop。
     *
     * Stop Octane。
     *
     * @return bool 任一路径成功即为 true
     */
    public function stop(): bool
    {
        return $this->runSupervisorCommand(['supervisorctl', 'stop', 'octane'])
            || $this->runArtisanOctaneCommand('octane:stop');
    }

    /**
     * 作用：重启 Octane（仅走 Supervisor 路径）。
     *
     * Restart Octane。
     *
     * Docker + Supervisor 环境中直接重启 octane program，比在 Web 请求里
     * stop/start 常驻进程更可控，也能避开 Swoole PID 权限问题。
     *
     * @return bool Supervisor 重启成功为 true
     */
    public function restart(): bool
    {
        return $this->restartBySupervisor();
    }

    /**
     * 作用：调用 `supervisorctl restart octane` 重启 Octane 程序组。
     *
     * 通过 Supervisor 重启 Octane。
     *
     * @return bool 命令成功为 true
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
     * 作用：以项目根目录为工作目录执行一条 supervisorctl 命令，失败时记录告警日志。
     *
     * 执行 supervisorctl 命令。
     *
     * @param  array  $command  命令及参数数组（如 ['supervisorctl','restart','octane']）
     * @return bool 命令退出码为 0 时返回 true
     *
     * 为什么：失败时把 stdout/stderr 一并写入 warning 日志，方便排查 Supervisor 权限或配置问题。
     */
    private function runSupervisorCommand(array $command): bool
    {
        $process = new Process($command, base_path());
        // Supervisor 重启常驻进程可能较慢，给到 30 秒超时
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
     * 作用：执行 `php artisan <command>`，作为无 Supervisor 环境（如本地）的兜底路径。
     *
     * 执行 Laravel Octane 原生命令，作为非 Supervisor 环境的兜底。
     *
     * @param  string  $command  artisan 子命令（如 octane:reload / octane:stop）
     * @return bool 命令退出码为 0 时返回 true
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
