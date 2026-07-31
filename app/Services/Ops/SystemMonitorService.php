<?php

namespace App\Services\Ops;

/**
 * 系统基础信息监控
 *
 * 作用：Ops Center 的“基础信息”卡片数据源，提供运行时环境（PHP/Laravel
 *       版本、时区）、系统 CPU/内存等轻量指标。
 * 与 AdvancedSystemMonitorService 的区别：本类偏向“应用进程 + 概要”视角，
 *       info() 里的内存是当前 PHP 进程占用；而 memoryUsage()/cpuUsage() 才
 *       读取 /proc 反映宿主机整体情况。
 */
class SystemMonitorService
{
    /**
     * 基础运行时与系统概要信息。
     *
     * 作用：汇总 PHP/Laravel 版本、时区、1 分钟 CPU 负载，以及当前 PHP
     *       进程的内存使用/峰值，供概览面板展示。
     *
     * @return array 环境版本、cpu_load 及 memory（当前进程占用）
     */
    public function info(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'timezone' => config('app.timezone'),

            // CPU负载（1分钟）
            // sys_getloadavg()[0] 在非 Linux 平台可能不可用，?? 0 兜底防报错
            'cpu_load' => sys_getloadavg()[0] ?? 0,

            // 内存使用
            // 注意：这里是“当前 PHP 进程”的内存，非宿主机整体（后者见 memoryUsage）
            'memory' => [
                // memory_get_usage(true) 返回字节，/1024/1024 转 MB
                'used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
        ];
    }

    /**
     * CPU使用率
     *
     * 作用：计算整机（汇总行）的 CPU 使用率百分比。
     * 为什么两次采样并相隔 0.5s：/proc/stat 的值是开机以来累计滴答，只有
     *       用两帧差值才能得出这段窗口内的瞬时使用率。
     *
     * @return float 使用率百分比（保留两位小数），无法计算时返回 0
     */
    public function cpuUsage(): float
    {
        $stat1 = $this->readCpuStat();

        usleep(500000); // 采样间隔 0.5 秒

        $stat2 = $this->readCpuStat();

        // 窗口内 idle 与 total 的增量
        $idle = $stat2['idle'] - $stat1['idle'];

        $total = $stat2['total'] - $stat1['total'];

        // 防止除零（两帧相同或异常时增量为 0）
        if ($total <= 0) {
            return 0;
        }

        // 使用率 = (1 - 空闲占比) * 100
        return round(
            (1 - $idle / $total) * 100,
            2
        );
    }

    /**
     * 内存信息
     *
     * 作用：读取 /proc/meminfo 计算宿主机整机内存的 total/used/free 及使用率。
     * 为什么用 MemAvailable 而非 MemFree 算 used：MemAvailable 计入了可回收的
     *       缓存/缓冲，比 MemFree 更接近“实际可再分配内存”，据此算出的已用量
     *       更贴近用户直觉。
     *
     * @return array total_mb/used_mb/free_mb（MB）与 usage_percent（%）
     */
    public function memoryUsage(): array
    {
        $data = file('/proc/meminfo');

        $mem = [];

        foreach ($data as $line) {

            [$key, $value] = explode(':', $line);

            // 去掉 " kB" 单位，只保留纯数字
            $mem[$key] = (int) filter_var(
                $value,
                FILTER_SANITIZE_NUMBER_INT
            );
        }

        $total = $mem['MemTotal'];

        $available = $mem['MemAvailable'];

        $used = $total - $available; // 已用 = 总量 - 可用（含可回收缓存）

        // /proc/meminfo 单位 kB，除以 1024 转 MB
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
     *
     * 作用：只解析 /proc/stat 的第一行（整机汇总的 "cpu" 行），返回 idle 与 total。
     * 为什么 idle 加上 $parts[4]：下标3是 idle、下标4是 iowait，二者都代表
     *       CPU 未在执行任务，合并计入空闲更准确；iowait 缺失时用 0 兜底。
     *
     * @return array 含 idle（空闲滴答）与 total（总滴答）
     */
    private function readCpuStat(): array
    {
        $line = file('/proc/stat')[0]; // 第一行即整机汇总行

        // 按连续空白切列
        $parts = preg_split(
            '/\s+/',
            trim($line)
        );

        array_shift($parts); // 丢弃首列 "cpu" 标签，剩下全是数值

        $total = array_sum($parts);

        $idle = $parts[3] + ($parts[4] ?? 0); // idle + iowait

        return [
            'idle' => $idle,
            'total' => $total,
        ];
    }

    /**
     * 总览
     *
     * 作用：对外的系统指标总览入口。当前实现委托给 SystemMetricsCollector
     *       采集，返回统一结构的实时指标。
     * 为什么保留上方被注释的旧实现：作为历史参考——早期直接调用本类的
     *       cpuUsage()/memoryUsage()，后重构为独立采集层以便复用与测试。
     *
     * @return array SystemMetricsCollector::collect() 的采集结果
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
