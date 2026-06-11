<?php

namespace App\Services\Ops;

/**
 * 网络流量监控服务（生产级）
 *
 * 通过读取 /proc/net/dev 获取网卡流量
 * 计算每秒吞吐量（KB/s, MB/s）
 */
class NetworkTrafficService
{
    /**
     * 读取原始网卡数据
     */
    public function getRaw(): array
    {
        $content = file_get_contents('/proc/net/dev');

        $lines = explode("\n", trim($content));
        $result = [];

        foreach ($lines as $index => $line) {
            if ($index < 2) continue; // 跳过表头

            $line = trim($line);
            if (empty($line)) continue;

            // eth0: 123 456 ...
            [$iface, $data] = explode(':', $line);

            $stats = preg_split('/\s+/', trim($data));

            $result[trim($iface)] = [
                'rx_bytes' => (int)$stats[0],
                'rx_packets' => (int)$stats[1],
                'tx_bytes' => (int)$stats[8],
                'tx_packets' => (int)$stats[9],
            ];
        }

        return $result;
    }

    /**
     * 计算带宽速率（需要缓存上一秒数据）
     */
    public function getSpeed(): array
    {
        $cacheKey = 'ops:net:last';

        $current = $this->getRaw();
        $last = cache()->get($cacheKey);

        cache()->put($cacheKey, $current, 10);

        if (!$last) {
            return [
                'status' => 'warming',
                'data' => $current
            ];
        }

        $result = [];

        foreach ($current as $iface => $data) {

            $lastData = $last[$iface] ?? null;
            if (!$lastData) continue;

            $result[$iface] = [
                'rx_kb_s' => round(($data['rx_bytes'] - $lastData['rx_bytes']) / 1024, 2),
                'tx_kb_s' => round(($data['tx_bytes'] - $lastData['tx_bytes']) / 1024, 2),

                'rx_mb_s' => round(($data['rx_bytes'] - $lastData['rx_bytes']) / 1024 / 1024, 3),
                'tx_mb_s' => round(($data['tx_bytes'] - $lastData['tx_bytes']) / 1024 / 1024, 3),
            ];
        }

        return [
            'status' => 'ok',
            'timestamp' => time(),
            'interfaces' => $result
        ];
    }
}
