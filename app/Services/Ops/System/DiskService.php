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
 *
 * 作用：Ops Center 磁盘监控的核心服务，负责调用 df 采集分区容量、过滤伪
 *       文件系统、汇总统计并可通过 WebSocket 广播。
 * 健壮性设计：df 在个别受限容器里可能失效，故内置 fallbackDisks() 用 PHP
 *       原生函数兜底，确保监控页面不会整块空白。
 */
class DiskService
{
    /**
     * 获取磁盘使用情况
     *
     * 作用：执行 df 采集磁盘分区，解析并过滤后返回可展示的分区列表。
     * 为什么有多重兜底：df 失败 -> fallbackDisks；解析结果为空 -> 同样兜底，
     *       双保险保证返回值始终可用。
     *
     * @return array 分区数组（每项含 filesystem/size/used/available/usage/mount）
     */
    public function getUsage(): array
    {
        // df -P 兼容 POSIX 输出格式（Docker 内推荐），使用数组命令避免 shell 拼接。
        $process = new Process(['df', '-P']);
        $process->setTimeout(5); // 5 秒超时，防止 df 在异常挂载点上卡死
        $process->run();

        // df 执行失败（命令不存在/被安全策略拦截）时走 PHP 原生兜底
        if (! $process->isSuccessful()) {
            return $this->fallbackDisks();
        }

        $disks = $this->parseDfOutput($process->getOutput());

        // 解析后若过滤到空（全是伪文件系统），仍用兜底保证有数据展示
        return $disks ?: $this->fallbackDisks();
    }

    /**
     * 解析 df -P 的输出。
     *
     * 独立成方法后，测试可以直接覆盖 Docker / Sail / OrbStack 的典型输出，
     * 避免只能依赖真实系统环境才能验证磁盘过滤规则。
     *
     * 作用：把 df 文本表格逐行拆解成结构化分区数组，并剔除伪文件系统。
     *
     * @param  string  $output  df -P 的完整标准输出
     * @return array 结构化分区数组
     */
    public function parseDfOutput(string $output): array
    {
        $disks = [];
        $lines = explode("\n", trim($output));

        // array_slice 从下标 1 开始 = 跳过 df 的表头行（Filesystem 1024-blocks ...）
        foreach (array_slice($lines, 1) as $line) {
            if (! $line) {
                continue; // 跳过空行
            }

            // 按连续空白切成各列
            $parts = preg_split('/\s+/', $line);

            // df -P 固定 6 列，少于 6 列视为异常/折行输出，跳过
            if (count($parts) < 6) {
                continue;
            }

            // 依次为：文件系统 / 总块数 / 已用 / 可用 / 使用率% / 挂载点
            [$filesystem, $size, $used, $avail, $usePercent, $mount] = $parts;

            // 过滤 tmpfs、/proc 等不该纳入容量监控的伪文件系统
            if ($this->shouldSkipFilesystem($filesystem, $mount)) {
                continue;
            }

            $disks[] = [
                'filesystem' => $filesystem,
                'size' => $this->toGB($size),
                'used' => $this->toGB($used),
                'available' => $this->toGB($avail),
                // usePercent 形如 "42%"，去掉 % 转成整数
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
     *
     * 作用：在 getUsage() 明细之上做聚合，输出总容量、已用、最高使用率等汇总字段。
     *
     * @return array 含 status/source/message/total_gb/used_gb/available_gb/max_usage/disks/checked_at
     */
    public function summary(): array
    {
        $disks = $this->getUsage();

        return [
            // 有分区则 ok，空则 empty，供前端切换展示状态
            'status' => $disks ? 'ok' : 'empty',
            'source' => 'df',
            'message' => $disks ? null : '未获取到可展示的真实磁盘分区',
            // array_column 抽取各分区某列再求和/取最大，得到汇总指标
            'total_gb' => round(array_sum(array_column($disks, 'size')), 2),
            'used_gb' => round(array_sum(array_column($disks, 'used')), 2),
            'available_gb' => round(array_sum(array_column($disks, 'available')), 2),
            // max 需非空数组，空时显式给 0 防止 max() 报错
            'max_usage' => $disks ? max(array_column($disks, 'usage')) : 0,
            'disks' => $disks,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 转换 KB -> GB（更友好展示）
     *
     * 作用：把 df 输出的 1024 字节块数（KB）换算成 GB。
     *
     * @param  string  $kb  df 给出的块数（字符串，PHP 会隐式转数字）
     * @return float 换算后的 GB 值（保留两位小数）
     */
    private function toGB(string $kb): float
    {
        // df -P 单位为 1024 字节块，连除两次 1024 即 KB -> MB -> GB
        return round($kb / 1024 / 1024, 2);
    }

    /**
     * df 在极少数容器环境下可能不可用或被安全策略限制。
     *
     * 这里使用 PHP 内置函数读取根目录和项目目录磁盘容量作为兜底，
     * 保证监控页面不会因为 df 异常而完全空白。
     *
     * 作用：不依赖外部命令，用 disk_total_space/disk_free_space 采集根目录与
     *       项目目录的容量，作为 df 失效时的降级数据源。
     *
     * @return array 与 parseDfOutput 结构一致的分区数组（单位换算为 GB）
     */
    private function fallbackDisks(): array
    {
        $disks = [];
        // array_unique 防止 base_path() 恰好等于 '/' 时重复采集
        $paths = array_unique([
            '/',
            base_path(),
        ]);

        foreach ($paths as $path) {
            // @ 抑制 open_basedir/权限受限时的告警，失败返回 false 由下方判断
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);

            // 任一取值失败或总量非正则跳过该路径
            if ($total === false || $free === false || $total <= 0) {
                continue;
            }

            $used = max($total - $free, 0); // max 防止极端情况下出现负值
            $mount = $path === base_path() ? base_path() : $path;

            $disks[] = [
                // 根目录标记 root，项目目录标记 application 便于区分
                'filesystem' => $path === '/' ? 'root' : 'application',
                // 这些内置函数返回字节，连除三次 1024 转成 GB
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
     *
     * 作用：判断某条 df 记录是否应被排除（tmpfs、cgroup、绑定挂载的 /etc/hosts 等）。
     * 为什么要过滤：这些伪/绑定文件系统不代表真实磁盘容量，纳入统计会污染
     *       总量与使用率，误导运维判断。
     *
     * @param  string  $filesystem  文件系统名（df 第一列）
     * @param  string  $mount  挂载点（df 最后一列）
     * @return bool 需要跳过返回 true
     */
    private function shouldSkipFilesystem(string $filesystem, string $mount): bool
    {
        // 按文件系统名前缀过滤内存型/虚拟文件系统
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

        // Docker 会把这些主机文件绑定挂载进容器，会被 df 当成独立“磁盘”，需排除
        $skipMounts = [
            '/etc/hosts',
            '/etc/hostname',
            '/etc/resolv.conf',
        ];

        if (in_array($mount, $skipMounts, true)) {
            return true;
        }

        // 再按挂载点前缀兜底过滤内核虚拟目录
        return str_starts_with($mount, '/proc')
            || str_starts_with($mount, '/sys')
            || str_starts_with($mount, '/dev');
    }

    /**
     * 推送磁盘数据到 WebSocket
     *
     * 作用：采集汇总后通过 DiskUpdated 事件广播给前端实时刷新，并返回同一份数据。
     *
     * @return array 与 summary() 相同的汇总结果（同时已广播）
     */
    public function push(): array
    {
        $data = $this->summary();

        // 广播实时磁盘数据，前端订阅 DiskUpdated 事件即可无刷新更新
        broadcast(new DiskUpdated($data));

        return $data;
    }
}
