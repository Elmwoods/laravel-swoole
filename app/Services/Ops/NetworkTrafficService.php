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
        $content = @file_get_contents('/proc/net/dev');

        if ($content === false) {
            return [];
        }

        $lines = explode("\n", trim($content));
        $result = [];

        foreach ($lines as $index => $line) {
            if ($index < 2) continue; // 跳过表头

            $line = trim($line);
            if (empty($line)) continue;

            // eth0: 123 456 ...
            // 使用 limit=2 避免异常格式里出现额外冒号时影响解析。
            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$iface, $data] = $parts;

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
        $now = microtime(true);
        $last = cache()->get($cacheKey);

        cache()->put($cacheKey, [
            'time' => $now,
            'data' => $current,
        ], 30);

        if (!$last) {
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

        $lastTime = (float) ($last['time'] ?? $now);
        $lastDataSet = (array) ($last['data'] ?? $last);
        $elapsed = max($now - $lastTime, 1);

        $result = [];
        $summary = [
            'rx_kb_s' => 0,
            'tx_kb_s' => 0,
            'rx_mb_s' => 0,
            'tx_mb_s' => 0,
        ];

        foreach ($current as $iface => $data) {

            $lastData = $lastDataSet[$iface] ?? null;
            if (!$lastData) continue;

            $rxBytes = max($data['rx_bytes'] - $lastData['rx_bytes'], 0);
            $txBytes = max($data['tx_bytes'] - $lastData['tx_bytes'], 0);

            $row = [
                'rx_kb_s' => round(($rxBytes / $elapsed) / 1024, 2),
                'tx_kb_s' => round(($txBytes / $elapsed) / 1024, 2),

                'rx_mb_s' => round(($rxBytes / $elapsed) / 1024 / 1024, 3),
                'tx_mb_s' => round(($txBytes / $elapsed) / 1024 / 1024, 3),
                'rx_packets' => max($data['rx_packets'] - $lastData['rx_packets'], 0),
                'tx_packets' => max($data['tx_packets'] - $lastData['tx_packets'], 0),
            ];

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
            'interfaces' => $result
        ];
    }

    /**
     * 首次采样时没有上一帧，返回 0 速率网卡列表，避免前端空表。
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
