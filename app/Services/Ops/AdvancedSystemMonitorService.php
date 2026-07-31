<?php

namespace App\Services\Ops;

use Symfony\Component\Process\Process;

/**
 * 高级系统监控（生产级）
 * CPU / Load / Memory / Swap / Network / Disk IO
 *
 * 作用：面向 Ops Center 运维中心的底层指标采集器，直接读取 Linux 的
 *       /proc 伪文件系统（/proc/stat、/proc/meminfo、/proc/net/dev）并
 *       调用外部命令（iostat）来获取宿主机的实时硬件指标。
 * 定位：相比 SystemMetricsCollector 只做轻量采集，本类提供“逐核 CPU、
 *       Swap、磁盘 IO”等更细粒度、更接近生产环境的监控数据。
 * 依赖环境：强依赖 Linux /proc 与 iostat，因此在 macOS / Windows 上部分
 *       方法会拿不到数据；调用方需对空数组/available=false 做兜底。
 */
class AdvancedSystemMonitorService
{
    /**
     * 总入口
     *
     * 作用：一次性聚合 CPU、负载、内存、Swap、网络、磁盘 IO 六大类指标，
     *       作为对外暴露的统一快照接口。
     *
     * @return array 六个键（cpu/load/memory/swap/network/disk）的聚合结果
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
     *
     * 作用：分别计算每个逻辑核心在采样窗口内的使用率百分比。
     * 为什么两次采样：/proc/stat 里的是“开机以来的累计滴答数”，属于单调
     *       递增的绝对值，无法直接得出瞬时使用率；必须相隔一小段时间采两次，
     *       用差值（delta）算出这段窗口内 idle 占比，再换算成使用率。
     *
     * @return array 每个核心一个元素的使用率数组（保留两位小数，单位 %）
     */
    public function cpuCores(): array
    {
        $stat1 = $this->readCpuStat();
        usleep(500000); // 采样间隔 0.5s：太短噪声大、太长响应慢，取折中
        $stat2 = $this->readCpuStat();

        $cores = [];

        foreach ($stat2 as $i => $core2) {

            // 两次采样核心集合理论上一致，异常时缺失则跳过该核心
            if (! isset($stat1[$i])) {
                continue;
            }

            // 窗口内 idle 增量与总增量的差值，才是这段时间真实的忙碌情况
            $idle = $core2['idle'] - $stat1[$i]['idle'];
            $total = $core2['total'] - $stat1[$i]['total'];

            // total>0 防止除零（例如两次采样值相同导致增量为 0）
            $usage = $total > 0
                ? (1 - $idle / $total) * 100
                : 0;

            $cores[] = round($usage, 2);
        }

        return $cores;
    }

    /**
     * Load Average
     *
     * 作用：返回系统 1/5/15 分钟平均负载。
     * 为什么用 sys_getloadavg：它是 PHP 内置函数，无需解析 /proc/loadavg，
     *       跨类保持一致；返回的三个值分别对应 1、5、15 分钟。
     *
     * @return array 含 1min/5min/15min 三个键的负载值
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
     *
     * 作用：读取 /proc/meminfo 并返回物理内存的 total/free/available（MB）。
     *
     * @return array total_mb/free_mb/available_mb 三个键（单位 MB）
     */
    public function memory(): array
    {
        $meminfo = file('/proc/meminfo'); // 每行形如 "MemTotal:  16342184 kB"

        $data = [];

        foreach ($meminfo as $line) {

            // 冒号左侧为指标名，右侧为数值+单位（kB）
            [$key, $value] = explode(':', $line);

            // FILTER_SANITIZE_NUMBER_INT 剥离 " kB" 等非数字字符，只留纯数字
            $data[$key] = (int) filter_var(
                $value,
                FILTER_SANITIZE_NUMBER_INT
            );
        }

        // /proc/meminfo 单位是 kB，除以 1024 转成 MB
        return [
            'total_mb' => $data['MemTotal'] / 1024,
            'free_mb' => $data['MemFree'] / 1024,
            'available_mb' => $data['MemAvailable'] / 1024,
        ];
    }

    /**
     * Swap
     *
     * 作用：读取 /proc/meminfo 中的交换分区总量与空闲量（MB）。
     * 为什么与 memory() 分开重复读取：保持方法单一职责，可独立调用；代价是
     *       多读一次文件，但 /proc/meminfo 是内存映射、开销极小。
     *
     * @return array total_mb/free_mb（单位 MB）
     */
    public function swap(): array
    {
        $meminfo = file('/proc/meminfo');

        $data = [];

        foreach ($meminfo as $line) {

            [$key, $value] = explode(':', $line);

            // 同 memory()：去掉 " kB" 单位只保留数字
            $data[$key] = (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        }

        // kB -> MB
        return [
            'total_mb' => $data['SwapTotal'] / 1024,
            'free_mb' => $data['SwapFree'] / 1024,
        ];
    }

    /**
     * 网络 IO
     *
     * 作用：读取 /proc/net/dev，返回各网卡开机以来的累计收发字节数。
     * 注意：这里是累计绝对值（非速率），若要速率需另行做两次采样求差
     *       （参见 NetworkTrafficService::getSpeed）。
     *
     * @return array 以网卡名为键，含 rx_bytes/tx_bytes 的数组
     */
    public function networkIO(): array
    {
        $lines = file('/proc/net/dev');

        $result = [];

        foreach ($lines as $line) {

            // 前两行是表头、没有冒号，据此跳过
            if (strpos($line, ':') === false) {
                continue;
            }

            // 冒号左侧是网卡名，右侧是空格分隔的计数列
            [$iface, $data] = explode(':', $line);

            // 按连续空白切分成各列：[0]=接收字节，[8]=发送字节（/proc/net/dev 列序固定）
            $stats = preg_split('/\s+/', trim($data));

            $result[trim($iface)] = [
                'rx_bytes' => (int) $stats[0],
                'tx_bytes' => (int) $stats[8],
            ];
        }

        return $result;
    }

    /**
     * Disk IO（简化版）
     *
     * 作用：调用外部命令 iostat 获取磁盘 IO 原始输出。
     * 为什么返回 raw 原文：磁盘 IO 指标列很多且随平台差异大，这里不做解析，
     *       原样透传给前端/上层，降低耦合。
     * 兜底：iostat 不存在或执行失败时返回 available=false 并附带 message/source，
     *       让调用方能够优雅降级而不是抛异常。
     *
     * @return array 成功时 available=true+raw；失败时 available=false+message+source
     */
    public function diskIO(): array
    {
        // 先探测命令是否存在，避免 Process 直接抛“command not found”
        if (! $this->hasExecutable('iostat')) {
            return [
                'available' => false,
                'raw' => '',
                'message' => 'iostat command unavailable',
                'source' => 'availability-check',
            ];
        }

        // -d 仅设备、-x 扩展统计、'1 1' = 采样间隔1秒共采1次；数组形式避免 shell 注入
        $process = new Process(['iostat', '-dx', '1', '1']);
        $process->setTimeout(5); // 5 秒超时，防止 iostat 卡死拖垮请求
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

    /**
     * 探测某个可执行命令是否存在于 PATH 中。
     *
     * 作用：纯 PHP 实现的 which，避免直接 shell 执行未知命令带来的风险。
     * 为什么不用 `which`：`which` 本身也可能不存在，且会再 fork 一个进程；
     *       这里遍历 $PATH 逐目录判断文件存在且可执行，更安全、跨平台。
     *
     * @param  string  $command  命令名（如 iostat）
     * @return bool 命令可执行返回 true，否则 false
     */
    protected function hasExecutable(string $command): bool
    {
        // 用系统 PATH 分隔符拆分（Linux 为 ':'），逐个目录查找
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));

        foreach ($paths as $path) {
            if ($path === '') {
                continue; // 跳过空段（如 PATH 里出现连续分隔符）
            }

            // 拼接候选完整路径，先去掉末尾多余分隔符再补一个
            $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$command;

            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CPU核心读取
     *
     * 作用：解析 /proc/stat，提取每个核心的累计 total 与 idle 滴答数。
     * 说明：/proc/stat 中以 "cpu" 开头的行含一行汇总（"cpu"）和逐核明细
     *       （"cpu0"、"cpu1"...），本方法把它们都收进以核心名为键的数组。
     * 为什么取 $parts[3]：该列固定为 idle（空闲）时间，是算使用率的关键项。
     *
     * @return array 以核心名为键、含 total/idle 的数组
     */
    private function readCpuStat(): array
    {
        $lines = file('/proc/stat');

        $cores = [];

        foreach ($lines as $line) {

            // 只处理 cpu 开头的行，忽略 intr/ctxt 等其它统计行
            if (! str_starts_with($line, 'cpu')) {
                continue;
            }

            // 按连续空白切列：首列是核心名，其余是各类 CPU 时间
            $parts = preg_split('/\s+/', trim($line));

            $name = array_shift($parts); // 取出并移除核心名，剩下全是数值列

            $total = array_sum($parts); // 所有时间列之和 = 总滴答数

            $idle = $parts[3] ?? 0; // 第 4 列（下标3）为 idle 空闲时间

            $cores[$name] = [
                'total' => $total,
                'idle' => $idle,
            ];
        }

        return $cores;
    }
}
