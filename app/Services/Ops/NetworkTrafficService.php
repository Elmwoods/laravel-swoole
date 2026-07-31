<?php

namespace App\Services\Ops;

/**
 * 网络流量监控服务（生产级）
 *
 * 通过读取 /proc/net/dev 获取网卡流量
 * 计算每秒吞吐量（KB/s, MB/s）
 *
 * 作用：Ops Center 网络监控服务。/proc/net/dev 只给累计字节，本类通过缓存
 *       上一帧、与当前帧求差再除以时间间隔，换算成实时带宽速率。
 * 关键机制：首帧无历史数据时返回 status=warming 的“预热”结果，第二帧起才
 *       能给出真实速率——这是所有基于累计计数器算速率的通用模式。
 */
class NetworkTrafficService
{
    /**
     * 读取原始网卡数据
     *
     * 作用：解析 /proc/net/dev，返回各网卡开机以来累计的收发字节与包数。
     * 为什么用 @file_get_contents 而非 file()：便于在非 Linux 环境下静默失败
     *       （返回 false）后统一兜底为空数组，不抛告警。
     *
     * @return array 以网卡名为键，含 rx_bytes/rx_packets/tx_bytes/tx_packets
     */
    public function getRaw(): array
    {
        $content = @file_get_contents('/proc/net/dev');

        // 非 Linux 或读取失败时返回空，交由上层降级处理
        if ($content === false) {
            return [];
        }

        $lines = explode("\n", trim($content));
        $result = [];

        foreach ($lines as $index => $line) {
            if ($index < 2) {
                continue;
            } // 跳过表头

            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // eth0: 123 456 ...
            // 使用 limit=2 避免异常格式里出现额外冒号时影响解析。
            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue; // 无冒号或格式异常的行跳过
            }

            [$iface, $data] = $parts;

            // 按连续空白切列。/proc/net/dev 列序固定：
            // [0]接收字节 [1]接收包 ... [8]发送字节 [9]发送包
            $stats = preg_split('/\s+/', trim($data));

            $result[trim($iface)] = [
                'rx_bytes' => (int) $stats[0],
                'rx_packets' => (int) $stats[1],
                'tx_bytes' => (int) $stats[8],
                'tx_packets' => (int) $stats[9],
            ];
        }

        return $result;
    }

    /**
     * 计算带宽速率（需要缓存上一秒数据）
     *
     * 作用：读取当前累计流量，与缓存中的上一帧求差、除以时间间隔，算出各网卡
     *       及汇总的实时收发速率（KB/s、MB/s、包/秒）。
     * 为什么需要缓存上一帧：/proc/net/dev 是单调递增计数器，单次读取无法得到
     *       速率；把上一帧存进缓存，本次减去它即得窗口内增量。
     *
     * @return array status=warming（首帧）或 ok；含 summary 汇总与 interfaces 明细
     */
    public function getSpeed(): array
    {
        $cacheKey = 'ops:net:last'; // 缓存上一帧的键

        $current = $this->getRaw();
        $now = microtime(true); // 高精度时间戳，用于精确计算间隔
        $last = cache()->get($cacheKey); // 取出上一帧（可能为空=首次调用）

        // 无论如何都把当前帧写回缓存，供下次调用作差；30 秒过期防止陈旧数据
        cache()->put($cacheKey, [
            'time' => $now,
            'data' => $current,
        ], 30);

        // 首帧没有历史可比，返回“预热中”并给出 0 速率网卡列表
        if (! $last) {
            return [
                'status' => 'warming',
                'timestamp' => time(),
                'summary' => [
                    'rx_kb_s' => 0,
                    'tx_kb_s' => 0,
                    'rx_mb_s' => 0,
                    'tx_mb_s' => 0,
                ],
                'interfaces' => $this->zeroInterfaces($current),
                'raw' => $current,
            ];
        }

        // 兼容旧缓存结构：优先取 last['time']/last['data']，缺失时退回整包
        $lastTime = (float) ($last['time'] ?? $now);
        $lastDataSet = (array) ($last['data'] ?? $last);
        // max(..., 1) 保证间隔至少 1 秒，既防除零又避免间隔过小放大噪声
        $elapsed = max($now - $lastTime, 1);

        $result = [];
        $summary = [
            'rx_kb_s' => 0,
            'tx_kb_s' => 0,
            'rx_mb_s' => 0,
            'tx_mb_s' => 0,
        ];

        foreach ($current as $iface => $data) {

            // 上一帧没有这块网卡（如新插入网卡）则跳过，无法作差
            $lastData = $lastDataSet[$iface] ?? null;
            if (! $lastData) {
                continue;
            }

            // 当前减上一帧得增量；max(...,0) 防止计数器回绕/网卡重置出现负值
            $rxBytes = max($data['rx_bytes'] - $lastData['rx_bytes'], 0);
            $txBytes = max($data['tx_bytes'] - $lastData['tx_bytes'], 0);

            $row = [
                // 增量字节 / 间隔秒 = 字节/秒，再 /1024 得 KB/s、/1024/1024 得 MB/s
                'rx_kb_s' => round(($rxBytes / $elapsed) / 1024, 2),
                'tx_kb_s' => round(($txBytes / $elapsed) / 1024, 2),

                'rx_mb_s' => round(($rxBytes / $elapsed) / 1024 / 1024, 3),
                'tx_mb_s' => round(($txBytes / $elapsed) / 1024 / 1024, 3),
                'rx_packets' => max($data['rx_packets'] - $lastData['rx_packets'], 0),
                'tx_packets' => max($data['tx_packets'] - $lastData['tx_packets'], 0),
            ];

            // 累加各网卡速率得到整机汇总
            $result[$iface] = $row;
            $summary['rx_kb_s'] += $row['rx_kb_s'];
            $summary['tx_kb_s'] += $row['tx_kb_s'];
            $summary['rx_mb_s'] += $row['rx_mb_s'];
            $summary['tx_mb_s'] += $row['tx_mb_s'];
        }

        return [
            'status' => 'ok',
            'timestamp' => time(),
            'summary' => [
                'rx_kb_s' => round($summary['rx_kb_s'], 2),
                'tx_kb_s' => round($summary['tx_kb_s'], 2),
                'rx_mb_s' => round($summary['rx_mb_s'], 3),
                'tx_mb_s' => round($summary['tx_mb_s'], 3),
            ],
            'interfaces' => $result,
        ];
    }

    /**
     * 首次采样时没有上一帧，返回 0 速率网卡列表，避免前端空表。
     *
     * 作用：把当前网卡集合映射为全 0 速率的占位结构，让预热态也能渲染出完整表格。
     *
     * @param  array  $interfaces  getRaw() 返回的当前网卡累计数据
     * @return array 以网卡名为键、各速率字段均为 0 的占位数组
     */
    private function zeroInterfaces(array $interfaces): array
    {
        return collect($interfaces)
            ->map(fn (): array => [
                'rx_kb_s' => 0,
                'tx_kb_s' => 0,
                'rx_mb_s' => 0,
                'tx_mb_s' => 0,
                'rx_packets' => 0,
                'tx_packets' => 0,
            ])
            ->all();
    }
}
