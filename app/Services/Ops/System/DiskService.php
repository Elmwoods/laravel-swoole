<?php

namespace App\Services\Ops\System;

use App\Events\Ops\System\DiskUpdated;

/**
 * DiskService
 * -------------------------------------------------
 * 系统磁盘监控服务（支持 Docker / Sail / Linux）
 * 使用 df 命令获取磁盘信息
 * -------------------------------------------------
 */
class DiskService
{
    /**
     * 获取磁盘使用情况
     *
     * @return array
     */
    public function getUsage(): array
    {
        // df -P 兼容 POSIX 输出格式（Docker 内推荐）
        $output = shell_exec("df -P | awk 'NR>1'");

        $disks = [];

        if (!$output) {
            return [
                'status' => 'error',
                'message' => '无法获取磁盘信息',
                'data' => []
            ];
        }

        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (!$line) continue;

            $parts = preg_split('/\s+/', $line);

            if (count($parts) < 6) continue;

            [$filesystem, $size, $used, $avail, $usePercent, $mount] = $parts;

            $disks[] = [
                'filesystem' => $filesystem,
                'size'       => $this->toGB($size),
                'used'       => $this->toGB($used),
                'available'  => $this->toGB($avail),
                'usage'      => (int) str_replace('%', '', $usePercent),
                'mount'      => $mount,
            ];
        }

        return $disks;
    }

    /**
     * 转换 KB -> GB（更友好展示）
     */
    private function toGB(string $kb): float
    {
        return round($kb / 1024 / 1024, 2);
    }

    /**
     * 推送磁盘数据到 WebSocket
     */
    public function push(): array
    {
        $data = $this->getUsage();

        broadcast(new DiskUpdated($data));

        return $data;
    }
}
