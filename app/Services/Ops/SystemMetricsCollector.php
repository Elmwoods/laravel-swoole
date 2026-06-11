<?php

namespace App\Services\Ops;

/**
 * 纯数据采集层（不做任何广播/存储）
 */
class SystemMetricsCollector
{
    public function collect(): array
    {
        return [
            'cpu' => $this->cpu(),
            'load' => sys_getloadavg(),
            'memory' => $this->memory(),
            'swap' => $this->swap(),
            'network' => $this->network(),
        ];
    }

    private function cpu(): float
    {
        $load = sys_getloadavg()[0];
        return round($load, 2);
    }

    private function memory(): array
    {
        $data = file('/proc/meminfo');

        $mem = [];

        foreach ($data as $line) {
            [$k, $v] = explode(':', $line);
            $mem[$k] = (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT);
        }

        return [
            'total' => $mem['MemTotal'],
            'available' => $mem['MemAvailable'],
        ];
    }

    private function swap(): array
    {
        $data = file('/proc/meminfo');

        $mem = [];

        foreach ($data as $line) {
            [$k, $v] = explode(':', $line);
            $mem[$k] = (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT);
        }

        return [
            'total' => $mem['SwapTotal'] ?? 0,
            'free' => $mem['SwapFree'] ?? 0,
        ];
    }

    private function network(): array
    {
        $lines = file('/proc/net/dev');

        $result = [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) continue;

            [$iface, $data] = explode(':', $line);
            $stats = preg_split('/\s+/', trim($data));

            $result[trim($iface)] = [
                'rx' => $stats[0],
                'tx' => $stats[8],
            ];
        }

        return $result;
    }
}
