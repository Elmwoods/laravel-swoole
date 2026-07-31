<?php

namespace Tests\Feature\Ops;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsAlertEvaluation;
use App\Models\OpsAlertSetting;
use App\Models\OpsInspection;
use App\Services\Admin\AdminPermissionRegistry;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\Log\OpsLogErrorWatcherService;
use App\Services\Ops\OpsInspectionService;
use App\Services\Ops\QueueMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Ops Center 第 12 阶段：自动巡检（Inspection）测试。
 *
 * 覆盖场景：巡检结果的概览/历史/详情接口与权限门（ops.inspections.view，仅 ops_admin 拥有），
 * 手动触发巡检的落库与审计，巡检详情对敏感字段（token/password/secret）的脱敏，
 * 巡检失败时联动告警的升起/复用/恢复关闭，以及“告警评估”检查项的瞬时失败降级为 warn、
 * 持续失败升级为 fail 的阈值逻辑。文件末尾自定义了 actingAsAdminWithPermissions 以就地建管理员与角色权限。
 */
class PhaseTwelveInspectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证巡检概览接口需登录：未认证访问返回 401。
     */
    public function test_inspection_summary_requires_authentication(): void
    {
        $this->getJson('/api/ops/inspections/summary')
            ->assertStatus(401);
    }

    /**
     * 验证巡检接口的权限门：仅有 dashboard.view（无 inspections.view）时概览与手动触发均 403。
     */
    public function test_inspection_routes_require_permission(): void
    {
        // 故意只给不相关的 dashboard.view，缺 inspections.view。
        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);

        $this->getJson('/api/ops/inspections/summary')
            ->assertStatus(403);

        $this->postJson('/api/ops/inspections/run')
            ->assertStatus(403);
    }

    /**
     * 验证权限注册：syncDefaults 后 ops.inspections.view 被登记并归属 ops_admin 角色，
     * 而 audit_viewer 角色不应拥有该权限（权限只授予运维管理员）。
     */
    public function test_inspection_permission_is_registered_for_ops_admin_only(): void
    {
        // 同步默认权限与角色映射。
        app(AdminPermissionRegistry::class)->syncDefaults();

        $this->assertDatabaseHas('admin_permissions', [
            'slug' => 'ops.inspections.view',
            'name' => '自动巡检查看',
            'group' => 'ops',
        ]);

        $this->assertTrue(AdminRole::query()
            ->where('slug', 'ops_admin')
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'ops.inspections.view'))
            ->exists());
        $this->assertFalse(AdminRole::query()
            ->where('slug', 'audit_viewer')
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'ops.inspections.view'))
            ->exists());
    }

    /**
     * 验证概览返回最新一条巡检、历史按时间倒序列出、详情才含 checks。
     * 造两条记录（old=light/pass，latest=full/warn），断言概览取 latest 且不含 checks（列表脱轻量化），
     * 历史列表首条为 latest、次条为 old，单条详情才带 checks.group。
     */
    public function test_inspection_summary_and_history_return_latest_records(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.inspections.view']);
        // 较早的一条：定时触发、通过。
        $old = OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'pass',
            'summary' => ['pass' => 2, 'warn' => 0, 'fail' => 0],
            'checks' => [['group' => '队列/调度', 'name' => 'Queue Workers', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']],
            'duration_ms' => 8,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
        ]);
        // 较新的一条：手动触发、有 warn（记录发起人管理员）。
        $latest = OpsInspection::query()->create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'warn',
            'summary' => ['pass' => 2, 'warn' => 1, 'fail' => 0],
            'checks' => [['group' => '发布自检', 'name' => 'APP_DEBUG', 'status' => 'warn', 'message' => 'debug on', 'hint' => 'turn off']],
            'duration_ms' => 12,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
        ]);

        $this->getJson('/api/ops/inspections/summary')
            ->assertOk()
            ->assertJsonPath('data.latest.id', $latest->id)
            ->assertJsonPath('data.summary.warn', 1)
            ->assertJsonMissingPath('data.latest.checks');

        $this->getJson('/api/ops/inspections/history')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $latest->id)
            ->assertJsonPath('data.items.1.id', $old->id)
            ->assertJsonMissingPath('data.items.0.checks');

        $this->getJson("/api/ops/inspections/history/{$latest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $latest->id)
            ->assertJsonPath('data.checks.0.group', '发布自检');
    }

    /**
     * 验证手动触发巡检：结果落库到 ops_inspections（含发起管理员），并写入 run 成功审计。
     * mock OpsInspectionService::run 返回预造记录、serializeDetail 返回详情，
     * 断言接口返回 pass、概览 summary，及两张表的落库。
     */
    public function test_manual_inspection_run_persists_history_and_writes_audit(): void
    {
        $admin = $this->actingAsAdminWithPermissions(['ops.inspections.view']);

        // mock 巡检服务：run 返回预造记录，避免真实执行各项检查。
        $this->mock(OpsInspectionService::class, function ($mock) use ($admin): void {
            $record = OpsInspection::query()->create([
                'type' => 'full',
                'trigger' => 'manual',
                'status' => 'pass',
                'summary' => ['pass' => 4, 'warn' => 0, 'fail' => 0],
                'checks' => [['group' => '巡检', 'name' => 'Mock', 'status' => 'pass', 'message' => 'ok', 'hint' => 'none']],
                'duration_ms' => 15,
                'started_at' => now()->subSecond(),
                'finished_at' => now(),
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
            ]);

            // 期望以 (类型 full, 触发 manual, 当前管理员) 调用一次 run。
            $mock->shouldReceive('run')
                ->once()
                ->with('full', 'manual', $admin)
                ->andReturn($record);
            $mock->shouldReceive('serializeDetail')
                ->once()
                ->with($record)
                ->andReturn([
                    'id' => $record->id,
                    'type' => 'full',
                    'trigger' => 'manual',
                    'status' => 'pass',
                    'summary' => ['pass' => 4, 'warn' => 0, 'fail' => 0],
                    'checks' => $record->checks,
                    'duration_ms' => 15,
                    'started_at' => optional($record->started_at)->toDateTimeString(),
                    'finished_at' => optional($record->finished_at)->toDateTimeString(),
                    'admin_email' => $admin->email,
                    'failure_message' => null,
                    'created_at' => optional($record->created_at)->toDateTimeString(),
                ]);
        });

        $this->postJson('/api/ops/inspections/run')
            ->assertOk()
            ->assertJsonPath('data.status', 'pass')
            ->assertJsonPath('data.summary.pass', 4);

        // 巡检记录应带发起管理员并落库。
        $this->assertDatabaseHas('ops_inspections', [
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'pass',
        ]);
        // 手动触发需留下 run 成功审计。
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'ops.inspections',
            'action' => 'run',
            'result' => 'success',
            'status_code' => 200,
        ]);
    }

    /**
     * 安全：验证巡检失败详情中的敏感信息被脱敏。
     * 造一条 fail 记录，checks 内含 token=/password=/secret 及 failure_message 含 authorization=，
     * 断言 checks.0.secret 被替换为 [FILTERED]，且响应 JSON 不含任何明文敏感值。
     */
    public function test_inspection_failure_detail_is_sanitized(): void
    {
        $this->actingAsAdminWithPermissions(['ops.inspections.view']);
        // 故意在多个字段埋入敏感明文，验证输出被过滤。
        $record = OpsInspection::query()->create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'fail',
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
            'checks' => [[
                'group' => '发布自检',
                'name' => 'Secret Check',
                'status' => 'fail',
                'message' => 'token=unsafe-token failed',
                'hint' => 'password=plain-password leaked',
                'secret' => 'unsafe-secret',
            ]],
            'failure_message' => 'authorization=unsafe failed',
            'duration_ms' => 20,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $detail = $this->getJson("/api/ops/inspections/history/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.checks.0.secret', '[FILTERED]')
            ->json('data');

        // 整个详情 JSON 中不得残留任何敏感明文。
        $json = json_encode($detail, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('unsafe-token', $json);
        $this->assertStringNotContainsString('plain-password', $json);
        $this->assertStringNotContainsString('unsafe-secret', $json);
    }

    /**
     * 验证巡检失败会升起 critical 告警并触发通知外发，且失败信息中的敏感内容不进入告警 payload。
     * 开启 telegram 通道并 fake 其接口，用含 token=leak 的失败巡检升起告警，
     * 断言告警 source=inspection、critical、open、hit_count=1，且 telegram 被调用、'leak' 不在告警字段中。
     */
    public function test_failed_inspection_raises_alert_and_dispatches_notification(): void
    {
        // 开启并配置 telegram 通道，使告警能真正走通知外发路径。
        config()->set('ops.alerts.telegram.enabled', true);
        config()->set('ops.alerts.telegram.bot_token', 'test-token');
        config()->set('ops.alerts.telegram.chat_id', '123456');
        // 设置层也打开 telegram 开关（配置 + 设置双重启用）。
        OpsAlertSetting::setValue('telegram_enabled', true);
        // fake telegram，避免真实外发。
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        // 失败信息含敏感字样 token=leak，用于验证不泄露到告警。
        $inspection = $this->failedInspection('token=leak failed');

        app(AlertCenterService::class)->raiseInspectionAlert($inspection);

        $alert = OpsAlert::query()->where('source', 'inspection')->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity); // 巡检失败告警定级为最高
        $this->assertSame('open', $alert->status);
        $this->assertSame(1, $alert->hit_count);         // 首次升起命中数为 1
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org'));

        // Leaked secrets from the failure must not reach the alert payload.
        $this->assertStringNotContainsString('leak', json_encode($alert->only(['message', 'context']), JSON_THROW_ON_ERROR));
    }

    /**
     * 验证重复失败复用同一条告警：两次升起后只存在 1 条 inspection 告警，hit_count 累加到 2。
     */
    public function test_repeated_failure_reuses_single_alert(): void
    {
        // 造两条独立的失败巡检，分别升起告警。
        $first = $this->failedInspection();
        $second = $this->failedInspection();

        $service = app(AlertCenterService::class);
        $service->raiseInspectionAlert($first);
        $service->raiseInspectionAlert($second);

        // 同源告警去重：只 1 条，命中数累计为 2。
        $alerts = OpsAlert::query()->where('source', 'inspection')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame(2, $alerts->first()->hit_count);
    }

    /**
     * 验证巡检恢复会关闭已开启的告警：先升起 open 告警，调用 resolveInspectionAlert 后状态转 resolved，
     * 并写入 inspection_recovered 事件（to_status=resolved）。
     */
    public function test_recovered_inspection_resolves_open_alert(): void
    {
        $service = app(AlertCenterService::class);
        // 先制造一条 open 的巡检告警。
        $service->raiseInspectionAlert($this->failedInspection());
        $this->assertSame('open', OpsAlert::query()->where('source', 'inspection')->value('status'));

        // 巡检恢复 → 关闭告警。
        $service->resolveInspectionAlert();

        $alert = OpsAlert::query()->where('source', 'inspection')->first();
        $this->assertSame('resolved', $alert->status);
        $this->assertDatabaseHas('ops_alert_events', [
            'alert_id' => $alert->id,
            'action' => 'inspection_recovered',
            'to_status' => 'resolved',
        ]);
    }

    /**
     * 验证“告警评估”瞬时失败降级为 warn：阈值设为 3，预置 2 条连续失败（未达阈值），
     * 巡检整体应为 warn，Alert Evaluation 检查项为 warn 且提示含“瞬时失败”，并且不应升起告警。
     */
    public function test_transient_alert_evaluation_failure_is_downgraded_to_warn(): void
    {
        // 连续失败阈值 3：低于该值视为瞬时抖动。
        config()->set('ops.inspections.alert_eval_fail_threshold', 3);
        // 预置 2 条失败历史（<3，未达阈值），并把 log/queue 检查桩成 pass。
        $this->stubLightInspectionServices(evaluateThrows: true, failureCount: 2);

        // 期望不升起告警（而是自动关闭）。
        $this->mockAlerts(expectRaise: false);

        $inspection = app(OpsInspectionService::class)->run('light');

        $this->assertSame('warn', $inspection->status);
        $check = collect($inspection->checks)->firstWhere('name', 'Alert Evaluation');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('瞬时失败', $check['message']);
    }

    /**
     * 验证“告警评估”持续失败升级为 fail：阈值 3，预置 3 条连续失败（达阈值），
     * 巡检整体为 fail、检查项为 fail，且应升起告警。
     */
    public function test_persistent_alert_evaluation_failure_is_fail(): void
    {
        config()->set('ops.inspections.alert_eval_fail_threshold', 3);
        // 预置 3 条失败历史（=阈值），视为持续故障。
        $this->stubLightInspectionServices(evaluateThrows: true, failureCount: 3);

        // 期望升起告警。
        $this->mockAlerts(expectRaise: true);

        $inspection = app(OpsInspectionService::class)->run('light');

        $this->assertSame('fail', $inspection->status);
        $check = collect($inspection->checks)->firstWhere('name', 'Alert Evaluation');
        $this->assertSame('fail', $check['status']);
    }

    /**
     * 预置评估历史 + 把 log/queue 检查桩成 pass，使巡检结果只由 Alert Evaluation 决定。
     */
    private function stubLightInspectionServices(bool $evaluateThrows, int $failureCount): void
    {
        // 预置 failureCount 条“告警评估失败”历史，用于让巡检判断连续失败次数是否达阈值。
        for ($i = 0; $i < $failureCount; $i++) {
            OpsAlertEvaluation::query()->create([
                'trigger' => 'schedule',
                'status' => 'failure',
                'detected_count' => 0,
                'auto_resolved_count' => 0,
                'duration_ms' => 1,
                'message' => 'boom',
            ]);
        }

        // 日志错误巡检桩为 pass（无检出），排除其对整体结果的干扰。
        $this->mock(OpsLogErrorWatcherService::class, function ($mock): void {
            $mock->shouldReceive('scan')->andReturn(['enabled' => true, 'detected' => 0, 'scanned' => 1]);
        });

        // 队列监控桩为健康（无失败任务、worker 运行中），使结果只取决于 Alert Evaluation。
        $this->mock(QueueMonitorService::class, function ($mock): void {
            $mock->shouldReceive('summary')->andReturn([
                'failed_jobs' => ['count' => 0],
                'workers' => ['running' => true],
                'queues' => [],
            ]);
        });
    }

    // mock 告警中心：evaluate 一律抛异常模拟评估失败；按 expectRaise 断言应升起还是应自动关闭告警。
    private function mockAlerts(bool $expectRaise): void
    {
        $this->mock(AlertCenterService::class, function ($mock) use ($expectRaise): void {
            // 评估过程抛异常，模拟“告警评估”检查项失败。
            $mock->shouldReceive('evaluate')->andThrow(new RuntimeException('boom'));

            if ($expectRaise) {
                // 持续失败：应升起告警、不应关闭。
                $mock->shouldReceive('raiseInspectionAlert')->once();
                $mock->shouldReceive('resolveInspectionAlert')->never();
            } else {
                // 瞬时失败：不升起告警、应自动关闭。
                $mock->shouldReceive('raiseInspectionAlert')->never();
                $mock->shouldReceive('resolveInspectionAlert')->once();
            }
        });
    }

    // 构造一条 fail 的轻量巡检记录（队列/调度检查失败），供告警升起/复用/恢复用例复用。
    private function failedInspection(string $failureMessage = '巡检检测到失败项。'): OpsInspection
    {
        return OpsInspection::query()->create([
            'type' => 'light',
            'trigger' => 'schedule',
            'status' => 'fail',
            'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
            'checks' => [[
                'group' => '队列/调度',
                'name' => 'Queue Workers',
                'status' => 'fail',
                'message' => $failureMessage,
                'hint' => '检查 worker。',
            ]],
            'failure_message' => $failureMessage,
            'duration_ms' => 10,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
    }

    protected function actingAsAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'Inspection Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
        $role = AdminRole::query()->create([
            'name' => 'Inspection Role',
            'slug' => 'inspection-role-'.uniqid(),
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $slug) {
            $permission = AdminPermission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => explode('.', $slug)[0], 'description' => $slug],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->attach($role->id);
        $this->actingAs($admin, 'admin');

        return $admin->refresh();
    }
}
