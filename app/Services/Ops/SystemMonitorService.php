<?php

namespace App\Services\Ops;

/**
 * 系统基础信息监控
 */
class SystemMonitorService
{
    public function info(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'timezone' => config('app.timezone'),

            // CPU负载（1分钟）
            'cpu_load' => sys_getloadavg()[0] ?? 0,

            // 内存使用
            'memory' => [
                'used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
        ];
    }

    /**
     * CPU使用率
     */
    public function cpuUsage(): float
    {
        $stat1 = $this->readCpuStat();

        usleep(500000);

        $stat2 = $this->readCpuStat();

        $idle = $stat2['idle'] - $stat1['idle'];

        $total = $stat2['total'] - $stat1['total'];

        if ($total <= 0) {
            return 0;
        }

        return round(
            (1 - $idle / $total) * 100,
            2
        );
    }

    /**
     * 内存信息
     */
    public function memoryUsage(): array
    {
        $data = file('/proc/meminfo');

        $mem = [];

        foreach ($data as $line) {

            [$key, $value] = explode(':', $line);

            $mem[$key] = (int) filter_var(
                $value,
                FILTER_SANITIZE_NUMBER_INT
            );
        }

        $total = $mem['MemTotal'];

        $available = $mem['MemAvailable'];

        $used = $total - $available;

        return [
            'total_mb' => round($total / 1024, 2),

            'used_mb' => round($used / 1024, 2),

            'free_mb' => round(
                $available / 1024,
                2
            ),

            'usage_percent' => round(
                $used / $total * 100,
                2
            ),
        ];
    }

    /**
     * CPU统计
     */
    private function readCpuStat(): array
    {
        $line = file('/proc/stat')[0];

        $parts = preg_split(
            '/\s+/',
            trim($line)
        );

        array_shift($parts);

        $total = array_sum($parts);

        $idle = $parts[3] + ($parts[4] ?? 0);

        return [
            'idle' => $idle,
            'total' => $total,
        ];
    }

    /**
     * 总览
     */
    public function summary(): array
    {
        //        return [
        //            'cpu' => $this->cpuUsage(),
        //
        //            'memory' => $this->memoryUsage(),
        //        ];
        return app(SystemMetricsCollector::class)->collect();
    }
}
