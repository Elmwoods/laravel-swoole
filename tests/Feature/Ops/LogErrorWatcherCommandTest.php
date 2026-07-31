<?php

namespace Tests\Feature\Ops;

use App\Models\OpsAlert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ops:logs:watch-errors 命令测试（日志错误监控 / Log Error Watcher）。
 *
 * 该命令扫描白名单内的日志文件，将新出现的 ERROR/EMERGENCY 等错误事件
 * 汇聚为运维告警（OpsAlert）。覆盖场景：新错误事件创建告警、重复错误累加 hit_count、
 * --dry-run 只打印诊断不落库、拒绝非白名单日志源（防路径穿越）、
 * 以及在告警设置表缺失时仍能兜底创建告警。
 *
 * 每个用例都基于临时目录中的真实日志文件与独立的 state 文件运行，
 * 由 setUp/tearDown 负责创建与清理，保证用例间隔离。
 */
class LogErrorWatcherCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        // 为每个用例创建独立的临时目录（uniqid 保证唯一），避免日志/状态文件互相污染
        $this->tempDir = sys_get_temp_dir().'/ops-log-watcher-command-'.uniqid();
        File::makeDirectory($this->tempDir);

        // 开启错误监控功能开关
        config()->set('ops.logs.error_watcher.enabled', true);
        // 将白名单日志源指向临时目录下的 laravel.log，测试仅扫描这一个受控文件
        config()->set('ops.logs.error_watcher.sources', [
            'laravel' => $this->tempDir.'/laravel.log',
        ]);
        // 状态文件（记录上次扫描偏移量）也放在临时目录，避免影响真实环境
        config()->set('ops.logs.error_watcher.state_file', $this->tempDir.'/state.json');
    }

    protected function tearDown(): void
    {
        // 清理临时目录，删除本用例产生的日志与状态文件
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * 验证命令为新错误事件创建告警，且相同错误再次出现时更新（累加 hit_count）而非重复建单：
     * 首次扫描创建一条 open/warning 告警且 hit_count=1；
     * 第二次扫描同一错误后，告警总数仍为 1，但 hit_count 累加到 2。
     */
    public function test_command_creates_or_updates_alert_for_new_error_events(): void
    {
        // 写入一条 ERROR 级别日志行，模拟真实的 Laravel 错误输出
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.ERROR: fputcsv(): the $escape parameter must be provided'.PHP_EOL);

        // --once 只扫一轮即退出；--since-offset-reset 重置偏移量，确保从头扫描整份日志
        $this->artisan('ops:logs:watch-errors', ['--once' => true, '--since-offset-reset' => true])
            ->expectsOutput('Scanned 1 sources, detected 1 new error events')
            ->assertExitCode(0);

        // 首次应创建一条来源为 logs:laravel、severity=warning、状态 open、命中次数 1 的告警
        $this->assertDatabaseHas('ops_alerts', [
            'source' => 'logs:laravel',
            'severity' => 'warning',
            'status' => 'open',
            'hit_count' => 1,
        ]);

        // 再次扫描同一份日志中的相同错误（同样重置偏移量令其重新检出）
        $this->artisan('ops:logs:watch-errors', ['--once' => true, '--since-offset-reset' => true])
            ->expectsOutput('Scanned 1 sources, detected 1 new error events')
            ->assertExitCode(0);

        // 仍只有 1 条告警（去重合并），但命中次数累加为 2
        $this->assertSame(1, OpsAlert::query()->count());
        $this->assertSame(2, OpsAlert::query()->firstOrFail()->hit_count);
    }

    /**
     * 验证 --dry-run 模式只打印诊断信息而不写入任何告警：
     * 输出中应包含检出的 source/level 以及修复建议（suggestion=），
     * 但 ops_alerts 表中不产生记录（count=0）。
     */
    public function test_dry_run_prints_diagnostics_without_writing_alerts(): void
    {
        // 写入一条 EMERGENCY 级别日志，验证高等级错误也能被诊断输出
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.EMERGENCY: Unable to create configured logger. Log [deprecations] is not defined.'.PHP_EOL);

        // --dry-run 只做预演诊断，不落库
        $this->artisan('ops:logs:watch-errors', [
            '--once' => true,
            '--dry-run' => true,
            '--since-offset-reset' => true,
        ])
            // 诊断行应标明来源与错误级别
            ->expectsOutputToContain('source=laravel level=EMERGENCY')
            // 诊断行应附带处置建议字段
            ->expectsOutputToContain('suggestion=')
            ->expectsOutput('Scanned 1 sources, detected 1 new error events')
            ->assertExitCode(0);

        // 预演模式下不创建任何告警
        $this->assertDatabaseCount('ops_alerts', 0);
    }

    /**
     * 验证命令拒绝非白名单日志源（安全防护，防止路径穿越读取任意文件）：
     * 传入 ../../.env 这类越权路径应被拒绝，输出错误提示并以退出码 1 失败。
     */
    public function test_command_rejects_non_whitelisted_source(): void
    {
        // --source 指定一个不在白名单内的相对路径（企图穿越到 .env），应被安全校验拦截
        $this->artisan('ops:logs:watch-errors', [
            '--source' => ['../../.env'],
            '--once' => true,
        ])
            ->expectsOutput('Invalid log source: ../../.env')
            ->assertExitCode(1);
    }

    /**
     * 验证告警设置表（ops_alert_settings）缺失时命令仍能兜底创建告警：
     * 即使无法读取通知配置，核心的告警建单也不应中断，
     * 并在告警 context 中标记 notification_failed=true、原因为 settings_unavailable。
     */
    public function test_command_still_creates_alert_when_notification_settings_table_is_missing(): void
    {
        File::put($this->tempDir.'/laravel.log', '[2026-07-16 12:00:00] local.ERROR: notification fallback test'.PHP_EOL);

        try {
            // 故意删除告警设置表，模拟通知配置不可用的降级场景
            Schema::dropIfExists('ops_alert_settings');

            $this->artisan('ops:logs:watch-errors', ['--once' => true, '--since-offset-reset' => true])
                ->expectsOutput('Scanned 1 sources, detected 1 new error events')
                ->assertExitCode(0);

            $alert = OpsAlert::query()->firstOrFail();

            // 告警仍被创建，来源正确
            $this->assertSame('logs:laravel', $alert->source);
            // context 中标记通知失败，并记录原因为"设置不可用"
            $this->assertTrue((bool) data_get($alert->context, 'notification_failed'));
            $this->assertSame('settings_unavailable', data_get($alert->context, 'notification_reason'));
        } finally {
            // 无论断言是否通过，都恢复被删除的设置表，避免污染后续用例
            $this->restoreOpsAlertSettingsTable();
        }
    }

    /**
     * 测试辅助方法：在 ops_alert_settings 表被删除后重建它，
     * 以便在 finally 中恢复数据库结构，防止影响其他测试。
     */
    private function restoreOpsAlertSettingsTable(): void
    {
        if (Schema::hasTable('ops_alert_settings')) {
            return;
        }

        Schema::create('ops_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->json('value');
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });
    }
}
