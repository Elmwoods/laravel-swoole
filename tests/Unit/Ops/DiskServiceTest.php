<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\System\DiskService;
use PHPUnit\Framework\TestCase;

/**
 * 磁盘监控服务测试。
 *
 * 重点覆盖 Docker / Sail / OrbStack 环境下 df -P 的典型输出，
 * 确认页面只展示真实有运维价值的磁盘，过滤掉伪文件系统和容器配置挂载。
 */
class DiskServiceTest extends TestCase
{
    public function test_parse_df_output_filters_virtual_and_duplicate_mounts(): void
    {
        $output = <<<'DF'
Filesystem     1024-blocks      Used Available Capacity Mounted on
overlay          340623360  71984716 268638644      22% /
tmpfs                65536         0     65536       0% /dev
shm                4096000         0   4096000       0% /dev/shm
/dev/vdb1        340623360  71984716 268638644      22% /etc/hosts
mac              482795520 199837696 282957824      42% /var/www/html
tmpfs             4096000         0   4096000       0% /sys/firmware
DF;

        $disks = (new DiskService())->parseDfOutput($output);

        $this->assertSame(['/', '/var/www/html'], array_column($disks, 'mount'));
        $this->assertSame(['overlay', 'mac'], array_column($disks, 'filesystem'));
        $this->assertSame(2, count($disks));
    }

    public function test_parse_df_output_returns_empty_when_only_virtual_mounts_exist(): void
    {
        $output = <<<'DF'
Filesystem     1024-blocks Used Available Capacity Mounted on
tmpfs                65536    0     65536       0% /dev
shm                4096000    0   4096000       0% /dev/shm
tmpfs             4096000    0   4096000       0% /sys/firmware
DF;

        $this->assertSame([], (new DiskService())->parseDfOutput($output));
    }
}
