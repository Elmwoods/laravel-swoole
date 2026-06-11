<?php

namespace App\Services\Ops;

/**
 * 系统进程管理（通用）
 */
class ProcessService
{
    public function list(): array
    {
        $output = shell_exec("ps aux --sort=-%cpu | head -n 20");

        return [
            'raw' => $output,
        ];
    }

    public function kill(int $pid): bool
    {
        shell_exec("kill -9 {$pid}");

        return true;
    }
}
