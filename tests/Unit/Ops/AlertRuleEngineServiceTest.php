<?php

namespace Tests\Unit\Ops;

use App\DTO\Ops\AlertDTO;
use App\Services\Ops\AlertRuleEngineService;
use Tests\TestCase;

/**
 * 告警规则引擎测试。
 */
class AlertRuleEngineServiceTest extends TestCase
{
    public function test_detects_disk_queue_docker_and_network_alerts(): void
    {
        config()->set('ops.alerts.thresholds.disk_usage_warning', 80);
        config()->set('ops.alerts.thresholds.disk_usage_critical', 95);
        config()->set('ops.alerts.thresholds.queue_pending_warning', 10);
        config()->set('ops.alerts.thresholds.failed_jobs_warning', 1);
        config()->set('ops.alerts.thresholds.network_mbps_warning', 5);
        config()->set('ops.alerts.thresholds.docker_exited_enabled', true);

        $alerts = app(AlertRuleEngineService::class)->detect([
            'disk' => [
                'disks' => [
                    ['mount' => '/', 'usage' => 96, 'filesystem' => 'overlay'],
                ],
            ],
            'queue' => [
                'queues' => [
                    ['name' => 'default', 'pending' => 12],
                ],
                'failed_jobs' => ['count' => 2],
            ],
            'docker' => [
                'unhealthy' => 1,
                'exited' => 1,
            ],
            'network' => [
                'summary' => [
                    'rx_mb_s' => 6,
                    'tx_mb_s' => 1,
                ],
            ],
        ]);

        $this->assertCount(6, $alerts);
        $this->assertContainsOnlyInstancesOf(AlertDTO::class, $alerts);
        $this->assertSame('critical', $alerts[0]->severity);
        $this->assertSame('disk', $alerts[0]->source);
        $this->assertSame('queue', $alerts[1]->source);
        $this->assertSame('docker', $alerts[3]->source);
        $this->assertSame('network', $alerts[5]->source);
    }

    public function test_alert_fingerprint_is_stable_for_same_target(): void
    {
        $first = new AlertDTO(
            source: 'disk',
            severity: 'warning',
            title: '磁盘使用率过高：/',
            message: 'first',
            context: ['target' => '/'],
        );
        $second = new AlertDTO(
            source: 'disk',
            severity: 'warning',
            title: '磁盘使用率过高：/',
            message: 'second',
            context: ['target' => '/'],
        );

        $this->assertSame($first->fingerprint(), $second->fingerprint());
    }
}
