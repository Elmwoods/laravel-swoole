<?php

namespace App\Services\Ops;

use Symfony\Component\Process\Process;

/**
 * 高级系统监控（生产级）
 * CPU / Load / Memory / Swap / Network / Disk IO
 */
class AdvancedSystemMonitorService
{
    /**
     * 总入口
     */
    public function summary(): array
    {
        return [
            'cpu' => $this->cpuCores(),

            'load' => $this->loadAverage(),

            'memory' => $this->memory(),

            'swap' => $this->swap(),

            'network' => $this->networkIO(),

            'disk' => $this->diskIO(),
        ];
    }

    /**
     * CPU 多核使用率
     */
    public function cpuCores(): array
    {
        $stat1 = $this->readCpuStat();
        usleep(500000);
        $stat2 = $this->readCpuStat();

        $cores = [];

        foreach ($stat2 as $i => $core2) {

            if (!isset($stat1[$i])) continue;

            $idle = $core2['idle'] - $stat1[$i]['idle'];
            $total = $core2['total'] - $stat1[$i]['total'];

            $usage = $total > 0
                ? (1 - $idle / $total) * 100
                : 0;

            $cores[] = round($usage, 2);
        }

        return $cores;
    }

    /**
     * Load Average
     */
    public function loadAverage(): array
    {
        $load = sys_getloadavg();

        return [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2],
        ];
    }

    /**
     * Memory + Swap
     */
    public function memory(): array
    {
        $meminfo = file('/proc/meminfo');

        $data = [];

        foreach ($meminfo as $line) {

            [$key, $value] = explode(':', $line);

            $data[$key] = (int) filter_var(
                $value,
                FILTER_SANITIZE_NUMBER_INT
            );
        }

        return [
            'total_mb' => $data['MemTotal'] / 1024,
            'free_mb' => $data['MemFree'] / 1024,
            'available_mb' => $data['MemAvailable'] / 1024,
        ];
    }

    /**
     * Swap
     */
    public function swap(): array
    {
        $meminfo = file('/proc/meminfo');

        $data = [];

        foreach ($meminfo as $line) {

            [$key, $value] = explode(':', $line);

            $data[$key] = (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        }

        return [
            'total_mb' => $data['SwapTotal'] / 1024,
            'free_mb' => $data['SwapFree'] / 1024,
        ];
    }

    /**
     * 网络 IO
     */
    public function networkIO(): array
    {
        $lines = file('/proc/net/dev');

        $result = [];

        foreach ($lines as $line) {

            if (strpos($line, ':') === false) continue;

            [$iface, $data] = explode(':', $line);

            $stats = preg_split('/\s+/', trim($data));

            $result[trim($iface)] = [
                'rx_bytes' => (int)$stats[0],
                'tx_bytes' => (int)$stats[8],
            ];
        }

        return $result;
    }

    /**
     * Disk IO（简化版）
     */
    public function diskIO(): array
    {
        if (! $this->hasExecutable('iostat')) {
            return [
                'available' => false,
                'raw' => '',
                'message' => 'iostat command unavailable',
                'source' => 'availability-check',
            ];
        }

        $process = new Process(['iostat', '-dx', '1', '1']);
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return [
                'available' => false,
                'raw' => '',
                'message' => 'iostat command unavailable',
                'source' => 'process',
            ];
        }

        return [
            'available' => true,
            'raw' => $process->getOutput(),
        ];
    }

    protected function hasExecutable(string $command): bool
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));

        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$command;

            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CPU核心读取
     */
    private function readCpuStat(): array
    {
        $lines = file('/proc/stat');

        $cores = [];

        foreach ($lines as $line) {

            if (!str_starts_with($line, 'cpu')) continue;

            $parts = preg_split('/\s+/', trim($line));

            $name = array_shift($parts);

            $total = array_sum($parts);

            $idle = $parts[3] ?? 0;

            $cores[$name] = [
                'total' => $total,
                'idle' => $idle,
            ];
        }

        return $cores;
    }
}
