<?php

namespace App\Services\Ops\System;

use App\Events\Ops\System\DiskUpdated;
use Symfony\Component\Process\Process;

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
     */
    public function getUsage(): array
    {
        // df -P 兼容 POSIX 输出格式（Docker 内推荐），使用数组命令避免 shell 拼接。
        $process = new Process(['df', '-P']);
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return $this->fallbackDisks();
        }

        $disks = $this->parseDfOutput($process->getOutput());

        return $disks ?: $this->fallbackDisks();
    }

    /**
     * 解析 df -P 的输出。
     *
     * 独立成方法后，测试可以直接覆盖 Docker / Sail / OrbStack 的典型输出，
     * 避免只能依赖真实系统环境才能验证磁盘过滤规则。
     */
    public function parseDfOutput(string $output): array
    {
        $disks = [];
        $lines = explode("\n", trim($output));

        foreach (array_slice($lines, 1) as $line) {
            if (! $line) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if (count($parts) < 6) {
                continue;
            }

            [$filesystem, $size, $used, $avail, $usePercent, $mount] = $parts;

            if ($this->shouldSkipFilesystem($filesystem, $mount)) {
                continue;
            }

            $disks[] = [
                'filesystem' => $filesystem,
                'size' => $this->toGB($size),
                'used' => $this->toGB($used),
                'available' => $this->toGB($avail),
                'usage' => (int) str_replace('%', '', $usePercent),
                'mount' => $mount,
            ];
        }

        return $disks;
    }

    /**
     * 获取磁盘使用率汇总。
     *
     * 第二阶段页面使用 summary 展示总容量、已用容量、最高使用率和明细表格。
     */
    public function summary(): array
    {
        $disks = $this->getUsage();

        return [
            'status' => $disks ? 'ok' : 'empty',
            'source' => 'df',
            'message' => $disks ? null : '未获取到可展示的真实磁盘分区',
            'total_gb' => round(array_sum(array_column($disks, 'size')), 2),
            'used_gb' => round(array_sum(array_column($disks, 'used')), 2),
            'available_gb' => round(array_sum(array_column($disks, 'available')), 2),
            'max_usage' => $disks ? max(array_column($disks, 'usage')) : 0,
            'disks' => $disks,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 转换 KB -> GB（更友好展示）
     */
    private function toGB(string $kb): float
    {
        return round($kb / 1024 / 1024, 2);
    }

    /**
     * df 在极少数容器环境下可能不可用或被安全策略限制。
     *
     * 这里使用 PHP 内置函数读取根目录和项目目录磁盘容量作为兜底，
     * 保证监控页面不会因为 df 异常而完全空白。
     */
    private function fallbackDisks(): array
    {
        $disks = [];
        $paths = array_unique([
            '/',
            base_path(),
        ]);

        foreach ($paths as $path) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);

            if ($total === false || $free === false || $total <= 0) {
                continue;
            }

            $used = max($total - $free, 0);
            $mount = $path === base_path() ? base_path() : $path;

            $disks[] = [
                'filesystem' => $path === '/' ? 'root' : 'application',
                'size' => round($total / 1024 / 1024 / 1024, 2),
                'used' => round($used / 1024 / 1024 / 1024, 2),
                'available' => round($free / 1024 / 1024 / 1024, 2),
                'usage' => (int) round(($used / $total) * 100),
                'mount' => $mount,
            ];
        }

        return $disks;
    }

    /**
     * 过滤 Docker/Linux 中不适合作为容量监控对象的伪文件系统。
     */
    private function shouldSkipFilesystem(string $filesystem, string $mount): bool
    {
        $skipPrefixes = [
            'tmpfs',
            'devtmpfs',
            'shm',
            'proc',
            'sysfs',
            'cgroup',
        ];

        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($filesystem, $prefix)) {
                return true;
            }
        }

        $skipMounts = [
            '/etc/hosts',
            '/etc/hostname',
            '/etc/resolv.conf',
        ];

        if (in_array($mount, $skipMounts, true)) {
            return true;
        }

        return str_starts_with($mount, '/proc')
            || str_starts_with($mount, '/sys')
            || str_starts_with($mount, '/dev');
    }

    /**
     * 推送磁盘数据到 WebSocket
     */
    public function push(): array
    {
        $data = $this->summary();

        broadcast(new DiskUpdated($data));

        return $data;
    }
}
