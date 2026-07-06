<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\NetworkTrafficService;
use App\Services\Ops\System\DiskService;
use Tests\TestCase;

/**
 * Ops Center 第二阶段系统资源接口测试。
 *
 * 使用 mock 隔离真实 Docker / Linux 主机差异，
 * 验证 Controller、路由和统一响应结构稳定可用。
 */
class PhaseTwoSystemMonitorTest extends TestCase
{
    public function test_network_api_returns_realtime_metrics(): void
    {
        $this->mock(NetworkTrafficService::class, function ($mock): void {
            $mock->shouldReceive('getSpeed')
                ->once()
                ->andReturn([
                    'status' => 'ok',
                    'timestamp' => time(),
                    'summary' => [
                        'rx_kb_s' => 1.23,
                        'tx_kb_s' => 0.45,
                        'rx_mb_s' => 0.001,
                        'tx_mb_s' => 0,
                    ],
                    'interfaces' => [
                        'eth0' => [
                            'rx_kb_s' => 1.23,
                            'tx_kb_s' => 0.45,
                            'rx_mb_s' => 0.001,
                            'tx_mb_s' => 0,
                            'rx_packets' => 10,
                            'tx_packets' => 8,
                        ],
                    ],
                ]);
        });

        $this->getJson('/api/ops/network')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.interfaces.eth0.rx_kb_s', 1.23);
    }

    public function test_disk_api_returns_filtered_disk_summary(): void
    {
        $this->mock(DiskService::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andReturn([
                    'total_gb' => 785.27,
                    'used_gb' => 259.23,
                    'available_gb' => 526.04,
                    'max_usage' => 42,
                    'disks' => [
                        [
                            'filesystem' => 'overlay',
                            'size' => 324.84,
                            'used' => 68.65,
                            'available' => 256.19,
                            'usage' => 22,
                            'mount' => '/',
                        ],
                        [
                            'filesystem' => 'mac',
                            'size' => 460.43,
                            'used' => 190.58,
                            'available' => 269.85,
                            'usage' => 42,
                            'mount' => '/var/www/html',
                        ],
                    ],
                    'checked_at' => now()->toDateTimeString(),
                ]);
        });

        $this->getJson('/api/ops/system/disk')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.max_usage', 42)
            ->assertJsonCount(2, 'data.disks')
            ->assertJsonPath('data.disks.0.mount', '/')
            ->assertJsonPath('data.disks.1.mount', '/var/www/html');
    }
}
