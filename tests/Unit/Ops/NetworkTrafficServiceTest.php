<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\NetworkTrafficService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 网络流量服务测试。
 *
 * 通过固定上一帧采样时间和网卡字节数，验证服务按真实时间差计算每秒速率，
 * 防止轮询间隔变化导致 KB/s、MB/s 展示偏大。
 */
class NetworkTrafficServiceTest extends TestCase
{
    public function test_speed_is_calculated_by_elapsed_seconds(): void
    {
        Cache::put('ops:net:last', [
            'time' => microtime(true) - 5,
            'data' => [
                'eth0' => [
                    'rx_bytes' => 1024,
                    'rx_packets' => 10,
                    'tx_bytes' => 2048,
                    'tx_packets' => 20,
                ],
            ],
        ], 30);

        $service = new class extends NetworkTrafficService {
            public function getRaw(): array
            {
                return [
                    'eth0' => [
                        'rx_bytes' => 6144,
                        'rx_packets' => 15,
                        'tx_bytes' => 12288,
                        'tx_packets' => 28,
                    ],
                ];
            }
        };

        $data = $service->getSpeed();

        $this->assertSame('ok', $data['status']);
        $this->assertEqualsWithDelta(1.0, $data['interfaces']['eth0']['rx_kb_s'], 0.05);
        $this->assertEqualsWithDelta(2.0, $data['interfaces']['eth0']['tx_kb_s'], 0.05);
        $this->assertSame(5, $data['interfaces']['eth0']['rx_packets']);
        $this->assertSame(8, $data['interfaces']['eth0']['tx_packets']);
    }
}
