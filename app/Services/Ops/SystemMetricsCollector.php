<?php

namespace App\Services\Ops;

/**
 * 纯数据采集层（不做任何广播/存储）
 *
 * 作用：Ops Center 的“无副作用”指标采集器，只负责从系统读取 CPU/负载/
 *       内存/Swap/网络原始数据并返回，绝不做广播或落库。
 * 为什么单独抽出这一层：把“采集”与“广播/缓存”解耦，采集逻辑可被
 *       SystemMonitorService::summary() 等多处复用，也便于单元测试。
 * 与本采集层返回值单位约定：memory/swap 保持 /proc 原始 kB，network 保持
 *       原始累计字节，是否换算/求速率交给上层决定。
 */
class SystemMetricsCollector
{
    /**
     * 采集全部系统指标快照。
     *
     * 作用：一次性返回 cpu/load/memory/swap/network 五类原始指标。
     *
     * @return array 五个键的采集结果（均为原始未换算数据）
     */
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

    /**
     * CPU 概要值。
     *
     * 作用：以 1 分钟平均负载近似代表 CPU 繁忙度（轻量、无需两次采样）。
     * 为什么不用 /proc/stat 精确使用率：本采集层追求单次、低开销，负载值
     *       足以驱动概览图表；精确逐核使用率由 AdvancedSystemMonitorService 提供。
     *
     * @return float 1 分钟负载（保留两位小数）
     */
    private function cpu(): float
    {
        $load = sys_getloadavg()[0];

        return round($load, 2);
    }

    /**
     * 物理内存原始量。
     *
     * 作用：读取 /proc/meminfo，返回 total 与 available（保持原始 kB 单位）。
     *
     * @return array total/available（单位 kB）
     */
    private function memory(): array
    {
        $data = file('/proc/meminfo');

        $mem = [];

        foreach ($data as $line) {
            // 冒号分隔键值；filter_var 去掉 " kB" 只留数字
            [$k, $v] = explode(':', $line);
            $mem[$k] = (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT);
        }

        return [
            'total' => $mem['MemTotal'],
            'available' => $mem['MemAvailable'],
        ];
    }

    /**
     * 交换分区原始量。
     *
     * 作用：读取 /proc/meminfo 的 Swap 总量与空闲量（原始 kB）。
     * 为什么用 ?? 0：部分环境（如未启用 swap 的容器）不含 Swap 字段，兜底为 0。
     *
     * @return array total/free（单位 kB）
     */
    private function swap(): array
    {
        $data = file('/proc/meminfo');

        $mem = [];

        foreach ($data as $line) {
            [$k, $v] = explode(':', $line);
            $mem[$k] = (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT);
        }

        return [
            'total' => $mem['SwapTotal'] ?? 0,
            'free' => $mem['SwapFree'] ?? 0,
        ];
    }

    /**
     * 网卡累计流量。
     *
     * 作用：读取 /proc/net/dev，返回各网卡开机以来累计的收发字节（绝对值）。
     *
     * @return array 以网卡名为键、含 rx/tx 的数组（累计字节，非速率）
     */
    private function network(): array
    {
        $lines = file('/proc/net/dev');

        $result = [];

        foreach ($lines as $line) {
            // 表头行不含冒号，据此跳过
            if (! str_contains($line, ':')) {
                continue;
            }

            // 冒号左侧网卡名、右侧计数列；按空白切列，[0]=rx字节 [8]=tx字节
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
