<?php

namespace Tests\Feature\Ops;

use App\Models\OpsInspection;
use App\Services\Admin\AdminIpAutoBanService;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertChannelHealthService;
use App\Services\Ops\AuditAnomalyScanService;
use App\Services\Ops\Log\OpsLogErrorWatcherService;
use App\Services\Ops\OnCallRotationService;
use App\Services\Ops\OpsInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 定时 ops 命令在瞬时失败时必须以退出码 0 结束，否则调度器会写 ERROR 日志，
 * 又被 ops:logs:watch-errors 采集成新告警，形成自澎环。
 *
 * 测试手法：把每个命令依赖的 Service mock 成"抛 RuntimeException"，
 * 断言命令仍返回退出码 0（吞掉瞬时异常、留待下一轮调度重试），从而打破上述回环。
 * 唯一例外是参数级错误（如非法 --type），那属于配置错误而非瞬时故障，应真实失败(退出码 1)。
 */
class ScheduledCommandResilienceTest extends TestCase
{
    use RefreshDatabase;

    // 验证告警评估命令：底层 evaluate 抛异常（采集器宕机）时命令仍退出码 0。
    public function test_evaluate_alerts_command_succeeds_even_when_evaluation_throws(): void
    {
        // mock evaluate('cli') 抛异常，模拟指标采集器不可用。
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->with('cli')->andThrow(new RuntimeException('collector down'));
        });

        $this->artisan('ops:alerts:evaluate')->assertExitCode(0);
    }

    // 验证巡检命令：巡检结果为 fail（有失败项）不等于命令执行失败，命令应退出码 0。
    public function test_inspection_run_command_succeeds_on_fail_result(): void
    {
        // 构造一份 status=fail 的巡检记录（业务层面失败，非命令执行异常）。
        $record = new OpsInspection([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'fail',
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
        ]);

        // mock run('light','schedule') 正常返回该 fail 记录。
        $this->mock(OpsInspectionService::class, function ($mock) use ($record): void {
            $mock->shouldReceive('run')->once()->with('light', 'schedule')->andReturn($record);
        });

        $this->artisan('ops:inspections:run', ['--type' => 'light'])->assertExitCode(0);
    }

    // 验证巡检命令：巡检服务 run 抛异常（如 DB 宕机）时命令仍退出码 0。
    public function test_inspection_run_command_succeeds_when_run_throws(): void
    {
        $this->mock(OpsInspectionService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:inspections:run', ['--type' => 'light'])->assertExitCode(0);
    }

    // 验证反例（参数错误 ≠ 瞬时故障）：非法 --type 属配置错误，命令应真实失败退出码 1。
    public function test_inspection_run_command_still_rejects_invalid_type(): void
    {
        // 不 mock 服务：bogus 类型在参数校验阶段就被拒，属于应当暴露的错误。
        $this->artisan('ops:inspections:run', ['--type' => 'bogus'])->assertExitCode(1);
    }

    // 验证日志错误监视命令：scan 抛异常（日志不可读）时命令仍退出码 0。
    public function test_watch_errors_command_succeeds_even_when_scan_throws(): void
    {
        $this->mock(OpsLogErrorWatcherService::class, function ($mock): void {
            // sources() 需返回一个日志源，命令才会进入 scan() 从而触发异常路径。
            $mock->shouldReceive('sources')->andReturn(['laravel' => '/tmp/laravel.log']);
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('log unreadable'));
        });

        // --once 只扫一轮即退出，避免测试里进入常驻循环。
        $this->artisan('ops:logs:watch-errors', ['--once' => true])->assertExitCode(0);
    }

    // 验证审计异常扫描命令：scan 抛异常（审计表不可用）时命令仍退出码 0。
    public function test_audit_anomaly_scan_command_succeeds_even_when_scan_throws(): void
    {
        $this->mock(AuditAnomalyScanService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('audit table down'));
        });

        $this->artisan('ops:audit:scan-anomalies')->assertExitCode(0);
    }

    // 验证告警升级命令：escalateStaleAlerts 抛异常（DB 宕机）时命令仍退出码 0。
    public function test_escalate_command_succeeds_even_when_escalation_throws(): void
    {
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('escalateStaleAlerts')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:alerts:escalate')->assertExitCode(0);
    }

    // 验证 IP 自动封禁命令：scan 抛异常（审计表不可用）时命令仍退出码 0。
    public function test_ip_auto_ban_command_succeeds_even_when_scan_throws(): void
    {
        $this->mock(AdminIpAutoBanService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('audit table down'));
        });

        $this->artisan('admin:ip-auto-ban')->assertExitCode(0);
    }

    // 验证通道健康自检命令：run（探活）抛异常时命令仍退出码 0。
    public function test_health_check_command_succeeds_even_when_probe_throws(): void
    {
        $this->mock(AlertChannelHealthService::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('probe down'));
        });

        $this->artisan('ops:alerts:health-check')->assertExitCode(0);
    }

    // 验证 SLA 违约扫描命令：scanSlaBreaches 抛异常（DB 宕机）时命令仍退出码 0。
    public function test_sla_scan_command_succeeds_even_when_scan_throws(): void
    {
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('scanSlaBreaches')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:alerts:sla-scan')->assertExitCode(0);
    }

    // 验证值班提醒命令：sendDueReminders 抛异常（DB 宕机）时命令仍退出码 0。
    public function test_on_call_remind_command_succeeds_even_when_service_throws(): void
    {
        $this->mock(OnCallRotationService::class, function ($mock): void {
            $mock->shouldReceive('sendDueReminders')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:on-call:remind')->assertExitCode(0);
    }

    // 验证周报命令：sendWeeklyReport 抛异常（DB 宕机）时命令仍退出码 0。
    public function test_weekly_report_command_succeeds_even_when_send_throws(): void
    {
        $this->mock(AlertCenterService::class, function ($mock): void {
            $mock->shouldReceive('sendWeeklyReport')->once()->andThrow(new RuntimeException('db down'));
        });

        $this->artisan('ops:alerts:weekly-report')->assertExitCode(0);
    }
}
