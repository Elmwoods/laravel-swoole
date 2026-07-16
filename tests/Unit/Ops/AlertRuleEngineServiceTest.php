<?php

namespace Tests\Unit\Ops;

use App\DTO\Ops\AlertDTO;
use App\Models\OpsAlertRule;
use App\Services\Ops\AlertRuleEngineService;
use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 告警规则引擎测试。
 */
class AlertRuleEngineServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_default_rules_are_synced_idempotently(): void
    {
        $registry = app(AlertRuleRegistryService::class);

        $first = $registry->syncDefaults();
        $second = $registry->syncDefaults();

        $this->assertCount(12, $first);
        $this->assertCount(12, $second);
        $this->assertSame(12, OpsAlertRule::query()->count());
        $this->assertDatabaseHas('ops_alert_rules', [
            'key' => 'disk_usage',
            'source' => 'disk',
            'metric' => 'usage_percent',
            'operator' => '>=',
        ]);
    }

    public function test_default_sync_preserves_user_thresholds_and_disabled_state(): void
    {
        $registry = app(AlertRuleRegistryService::class);

        $registry->syncDefaults();

        OpsAlertRule::query()
            ->where('key', 'disk_usage')
            ->firstOrFail()
            ->forceFill([
                'warning_threshold' => 70,
                'critical_threshold' => 90,
                'is_active' => false,
            ])
            ->save();

        $registry->syncDefaults();

        $this->assertDatabaseHas('ops_alert_rules', [
            'key' => 'disk_usage',
            'warning_threshold' => 70,
            'critical_threshold' => 90,
            'is_active' => false,
        ]);
    }

    public function test_detect_syncs_default_rules_before_evaluation(): void
    {
        $this->assertSame(0, OpsAlertRule::query()->count());

        app(AlertRuleEngineService::class)->detect([
            'disk' => ['disks' => []],
            'queue' => ['queues' => [], 'failed_jobs' => ['count' => 0]],
            'docker' => ['unhealthy' => 0, 'exited' => 0],
            'network' => ['summary' => ['rx_mb_s' => 0, 'tx_mb_s' => 0]],
        ]);

        $this->assertSame(12, OpsAlertRule::query()->count());
        $this->assertDatabaseHas('ops_alert_rules', [
            'key' => 'disk_usage',
            'is_active' => true,
        ]);
    }

    public function test_database_rules_override_config_defaults(): void
    {
        config()->set('ops.alerts.thresholds.disk_usage_warning', 90);
        config()->set('ops.alerts.thresholds.disk_usage_critical', 95);

        OpsAlertRule::query()->create([
            'key' => 'disk_usage',
            'name' => '磁盘使用率',
            'source' => 'disk',
            'metric' => 'usage_percent',
            'operator' => '>=',
            'warning_threshold' => 70,
            'critical_threshold' => 80,
            'unit' => '%',
            'is_active' => true,
            'description' => 'Disk rule',
            'sort_order' => 10,
        ]);

        $alerts = app(AlertRuleEngineService::class)->detect([
            'disk' => [
                'disks' => [
                    ['mount' => '/', 'usage' => 75, 'filesystem' => 'overlay'],
                ],
            ],
        ]);

        $this->assertCount(1, $alerts);
        $this->assertSame('warning', $alerts[0]->severity);
    }

    public function test_disabled_database_rules_do_not_trigger_alerts(): void
    {
        config()->set('ops.alerts.thresholds.disk_usage_warning', 50);
        config()->set('ops.alerts.thresholds.disk_usage_critical', 90);

        OpsAlertRule::query()->create([
            'key' => 'disk_usage',
            'name' => '磁盘使用率',
            'source' => 'disk',
            'metric' => 'usage_percent',
            'operator' => '>=',
            'warning_threshold' => 50,
            'critical_threshold' => 90,
            'unit' => '%',
            'is_active' => false,
            'description' => 'Disk rule',
            'sort_order' => 10,
        ]);

        $alerts = app(AlertRuleEngineService::class)->detect([
            'disk' => [
                'disks' => [
                    ['mount' => '/', 'usage' => 95, 'filesystem' => 'overlay'],
                ],
            ],
        ]);

        $this->assertSame([], $alerts);
    }

    public function test_extended_system_rules_detect_resource_and_service_failures(): void
    {
        app(AlertRuleRegistryService::class)->syncDefaults();

        $alerts = app(AlertRuleEngineService::class)->detect([
            'system' => [
                'cpu_percent' => 95,
                'memory' => ['percent' => 92],
            ],
            'redis' => ['connected' => false],
            'mysql' => ['connected' => false],
            'octane' => ['running' => false, 'process_count' => 0],
            'supervisor' => [
                ['name' => 'octane', 'state' => 'STOPPED'],
            ],
        ]);

        $this->assertSame(
            ['system', 'system', 'redis', 'mysql', 'octane', 'supervisor'],
            collect($alerts)->pluck('source')->all(),
        );
    }

    public function test_default_rule_registry_includes_extended_alert_rules(): void
    {
        $registry = app(AlertRuleRegistryService::class);

        $registry->syncDefaults();

        foreach (['system_cpu', 'system_memory', 'redis_connected', 'mysql_connected', 'octane_running', 'supervisor_process_down'] as $key) {
            $this->assertDatabaseHas('ops_alert_rules', [
                'key' => $key,
            ]);
        }
    }
}
