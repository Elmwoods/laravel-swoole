<?php

namespace App\Services;

class OctaneWorkerManager
{
    /**
     * 获取 Octane state 文件
     */
    public function stateFile(): string
    {
        return storage_path('logs/octane-server-state.json');
    }

    /**
     * 获取 Master PID
     */
    public function getMasterPid(): ?int
    {
        if (! file_exists($this->stateFile())) {
            return null;
        }

        $state = json_decode(file_get_contents($this->stateFile()), true);

        return $state['masterProcessId'] ?? null;
    }

    /**
     * 发送 reload 信号（Swoole）
     */
    public function reload(): bool
    {
        $pid = $this->getMasterPid();

        if (! $pid) {
            return false;
        }

        return posix_kill($pid, SIGUSR1);
    }

    /**
     * 平滑停止 Octane
     */
    public function stop(): bool
    {
        $pid = $this->getMasterPid();

        if (! $pid) {
            return false;
        }

        return posix_kill($pid, SIGTERM);
    }

    /**
     * 是否运行中
     */
    public function isRunning(): bool
    {
        return $this->getMasterPid() !== null;
    }

    /**
     * 获取完整状态
     */
    public function status(): array
    {
        return [
            'running' => $this->isRunning(),
            'master_pid' => $this->getMasterPid(),
            'state_file_exists' => file_exists($this->stateFile()),
        ];
    }
}
