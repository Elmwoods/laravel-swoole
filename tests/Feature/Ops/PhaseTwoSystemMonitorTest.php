<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\NetworkTrafficService;
use App\Services\Ops\System\DiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ops Center 第二阶段系统资源接口测试。
 *
 * 使用 mock 隔离真实 Docker / Linux 主机差异，
 * 验证 Controller、路由和统一响应结构稳定可用。
 *
 * 具体覆盖 /api/ops/network 网络实时速率与 /api/ops/system/disk 磁盘汇总两个接口：
 * 底层 Service 被 mock 成固定形状的返回值，从而只校验 Controller 透传、
 * 路由绑定与统一响应外壳（code=0 + data）的正确性，不依赖真实主机。
 */
class PhaseTwoSystemMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // 两个接口都需要 ops.system.view 权限，统一在 setUp 里登录一个带该权限的 admin。
        parent::setUp();

        $this->actingAsAdminWithPermissions(['ops.system.view']);
    }

    // 验证网络接口把 Service::getSpeed 返回的实时速率结构原样透传，且响应外壳 code=0、汇总/网卡字段可读。
    public function test_network_api_returns_realtime_metrics(): void
    {
        // mock NetworkTrafficService 返回固定的速率快照，隔离真实网卡采样。
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

    // 验证磁盘接口把 Service::summary 返回的磁盘汇总透传：最大使用率、磁盘条目数与各挂载点顺序均正确。
    public function test_disk_api_returns_filtered_disk_summary(): void
    {
        // mock DiskService 返回固定的两块盘汇总，隔离真实 df 输出。
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
