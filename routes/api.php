<?php

use App\Events\Ops\TestEvent;
use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\Ops\AdvancedSystemController;
use App\Http\Controllers\Admin\Ops\AlertController;
use App\Http\Controllers\Admin\Ops\AlertIngestController;
use App\Http\Controllers\Admin\Ops\AlertNoteController;
use App\Http\Controllers\Admin\Ops\AlertPresetController;
use App\Http\Controllers\Admin\Ops\AlertRuleController;
use App\Http\Controllers\Admin\Ops\AlertSilenceController;
use App\Http\Controllers\Admin\Ops\DashboardController;
use App\Http\Controllers\Admin\Ops\DockerController;
use App\Http\Controllers\Admin\Ops\Log\LogController;
use App\Http\Controllers\Admin\Ops\MetricsController;
use App\Http\Controllers\Admin\Ops\NetworkController;
use App\Http\Controllers\Admin\Ops\OctaneController;
use App\Http\Controllers\Admin\Ops\OnCallController;
use App\Http\Controllers\Admin\Ops\OnCallDashboardController;
use App\Http\Controllers\Admin\Ops\OpsInspectionController;
use App\Http\Controllers\Admin\Ops\OpsReleaseCheckController;
use App\Http\Controllers\Admin\Ops\QueueController;
use App\Http\Controllers\Admin\Ops\RedisMetricsController;
use App\Http\Controllers\Admin\Ops\RedisMonitorController;
use App\Http\Controllers\Admin\Ops\SecurityOverviewController;
use App\Http\Controllers\Admin\Ops\ShiftHandoverController;
use App\Http\Controllers\Admin\Ops\SupervisorController;
use App\Http\Controllers\Admin\Ops\System\DiskController;
use App\Http\Controllers\Admin\Ops\System\DiskPushController;
use App\Http\Controllers\Admin\Ops\SystemMonitorController;
use App\Http\Controllers\Admin\Security\AdminAuditLogController;
use App\Http\Controllers\Admin\Security\AdminAuditPresetController;
use App\Http\Controllers\Admin\Security\AdminIpRuleController;
use App\Http\Controllers\Admin\Security\AdminRoleController;
use App\Http\Controllers\Admin\Security\AdminUserController;
use Illuminate\Support\Facades\Route;

// API 路由总入口。分三大块：
//   1) /admin  —— 后台账户/认证/RBAC/审计/安全（IP 规则）管理。
//   2) /ops    —— 运维中心（Ops Center）监控与告警，全部需登录 + 细粒度权限。
//   3) 组外路由 —— /ops/metrics（Prometheus 指标导出）与 /ingest/alerts（入站 webhook 告警），
//      故意不放进 /ops 组：它们用控制器内的 token 守卫而非会话认证，供外部系统调用（详见文件末尾）。
// 中间件约定：admin.auth = 后台会话认证；admin.permission:X = 需要权限 X；
//   admin.audit:模块,动作 = 记录一条后台审计日志（通常挂在写操作/敏感读操作上）。

// ===== 后台管理区（/admin，仅 web 会话中间件；认证在内部子组按需叠加）=====
Route::prefix('/admin')
    ->middleware('web')
    ->group(function (): void {
        // 认证子组：登录/登出/2FA/会话与可信设备管理。
        // /password-key 与 /login 无需登录（登录入口本身）；/two-factor/* 是登录过程中的 2FA 步骤；
        // 其余（/me、/logout、会话、可信设备）都在下面的 admin.auth 子组内，需已登录。
        Route::prefix('auth')->group(function (): void {
            Route::get('/password-key', [AdminAuthController::class, 'passwordKey']);
            Route::post('/login', [AdminAuthController::class, 'login']);

            // 已登录才可访问：当前用户信息、登出、登录历史、可信设备、活跃会话（含踢下线）。
            Route::middleware('admin.auth')->group(function (): void {
                Route::get('/me', [AdminAuthController::class, 'me']);
                Route::post('/logout', [AdminAuthController::class, 'logout']);
                Route::get('/login-history', [AdminAuthController::class, 'loginHistory']);
                Route::get('/trusted-devices', [AdminAuthController::class, 'trustedDevices']);
                Route::post('/trusted-devices/{device}/revoke', [AdminAuthController::class, 'revokeTrustedDevice']);
                Route::get('/sessions', [AdminAuthController::class, 'activeSessions']);
                Route::post('/sessions/revoke-others', [AdminAuthController::class, 'revokeOtherSessions']);
                Route::post('/sessions/{session}/revoke', [AdminAuthController::class, 'revokeSession']);
            });

            // 2FA 步骤：confirm=首次绑定后确认启用；challenge=登录时校验二次验证码（均属登录流程，不套 admin.auth）。
            Route::post('/two-factor/confirm', [AdminAuthController::class, 'confirmTwoFactor']);
            Route::post('/two-factor/challenge', [AdminAuthController::class, 'challengeTwoFactor']);
        });

        // 后台管理功能：统一要求已登录（admin.auth），再按功能分别叠加 admin.permission 权限。
        Route::middleware('admin.auth')->group(function (): void {
            // 管理员账户管理：需 admin.users.manage 权限；写操作均记审计（create/update/reset_password/two_factor_reset）。
            Route::middleware('admin.permission:admin.users.manage')->group(function (): void {
                Route::get('/users', [AdminUserController::class, 'index']);
                Route::post('/users', [AdminUserController::class, 'store'])
                    ->middleware('admin.audit:admin.users,create');
                Route::put('/users/{adminUser}', [AdminUserController::class, 'update'])
                    ->middleware('admin.audit:admin.users,update');
                Route::post('/users/{adminUser}/reset-password', [AdminUserController::class, 'resetPassword'])
                    ->middleware('admin.audit:admin.users,reset_password');
                Route::post('/users/{adminUser}/two-factor/reset', [AdminUserController::class, 'resetTwoFactor'])
                    ->middleware('admin.audit:admin.users,two_factor_reset');
            });

            // 角色/权限（RBAC）管理：需 admin.roles.manage 权限；创建/更新记审计。
            Route::middleware('admin.permission:admin.roles.manage')->group(function (): void {
                Route::get('/roles', [AdminRoleController::class, 'index']);
                Route::post('/roles', [AdminRoleController::class, 'store'])
                    ->middleware('admin.audit:admin.roles,create');
                Route::put('/roles/{adminRole}', [AdminRoleController::class, 'update'])
                    ->middleware('admin.audit:admin.roles,update');
            });

            // 审计日志查询：均需 admin.audit.view 权限。
            // export 自身是敏感读取，额外记一条 audit 审计；facets/presets/列表为只读查询不记审计。
            Route::get('/audit-logs/facets', [AdminAuditLogController::class, 'facets'])
                ->middleware('admin.permission:admin.audit.view');
            Route::get('/audit-logs/export', [AdminAuditLogController::class, 'export'])
                ->middleware(['admin.permission:admin.audit.view', 'admin.audit:admin.audit,export']);
            // 审计筛选预设（保存/复用常用查询条件）。
            Route::middleware('admin.permission:admin.audit.view')->group(function (): void {
                Route::get('/audit-logs/presets', [AdminAuditPresetController::class, 'index']);
                Route::post('/audit-logs/presets', [AdminAuditPresetController::class, 'store']);
                Route::delete('/audit-logs/presets/{preset}', [AdminAuditPresetController::class, 'destroy']);
            });
            Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
                ->middleware('admin.permission:admin.audit.view');

            // 后台安全准入管理：需 admin.security.manage 权限。CIDR IP 规则的增删改 + IP 准入全局开关/模式设置。
            Route::middleware('admin.permission:admin.security.manage')->group(function (): void {
                Route::get('/ip-rules', [AdminIpRuleController::class, 'index']);
                Route::post('/ip-rules', [AdminIpRuleController::class, 'store']);
                Route::patch('/ip-rules/{rule}', [AdminIpRuleController::class, 'update']);
                Route::delete('/ip-rules/{rule}', [AdminIpRuleController::class, 'destroy']);
                Route::put('/ip-access/settings', [AdminIpRuleController::class, 'updateSettings']);
            });
        });
    });

// ===== 运维中心区（/ops）=====
// 整组要求 web 会话 + admin.auth 登录；每个子功能再叠加各自的 ops.* 权限（查看 view / 控制 control / 管理 manage）。
// 会触发副作用的操作（reload/restart/run/评估/告警处置等）几乎都叠加 admin.audit 记审计。
Route::prefix('/ops')
    ->middleware(['web', 'admin.auth'])
    ->group(function (): void {
        // 运维总览仪表盘。
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('admin.permission:ops.dashboard.view');

        // 安全态势总览（登录风控 / IP 规则 / 封禁等汇总）。
        Route::get('/security/overview', [SecurityOverviewController::class, 'overview'])
            ->middleware('admin.permission:ops.security.view');

        // 发布前检查（release check）：需 ops.release.view；run（实际执行检查）记审计。
        Route::prefix('release-check')
            ->middleware('admin.permission:ops.release.view')
            ->group(function (): void {
                Route::get('/overview', [OpsReleaseCheckController::class, 'overview']);
                Route::post('/run', [OpsReleaseCheckController::class, 'run'])
                    ->middleware('admin.audit:ops.release,run');
                Route::get('/history', [OpsReleaseCheckController::class, 'history']);
                Route::get('/history/{record}', [OpsReleaseCheckController::class, 'show']);
            });

        // 自动巡检（inspections）：需 ops.inspections.view；run（手动触发巡检）记审计。
        Route::prefix('inspections')
            ->middleware('admin.permission:ops.inspections.view')
            ->group(function (): void {
                Route::get('/summary', [OpsInspectionController::class, 'summary']);
                Route::get('/trend', [OpsInspectionController::class, 'trend']);
                Route::get('/history', [OpsInspectionController::class, 'history']);
                Route::get('/history/{record}', [OpsInspectionController::class, 'show']);
                Route::post('/run', [OpsInspectionController::class, 'run'])
                    ->middleware('admin.audit:ops.inspections,run');
            });

        // Octane 应用服务器管控：需 ops.system.view；reload/restart/stop 均为控制操作，记审计。
        Route::prefix('octane')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/status', [OctaneController::class, 'status']);
                Route::post('/reload', [OctaneController::class, 'reload'])
                    ->middleware('admin.audit:ops.octane,reload');
                Route::post('/restart', [OctaneController::class, 'restart'])
                    ->middleware('admin.audit:ops.octane,restart');
                Route::post('/stop', [OctaneController::class, 'stop'])
                    ->middleware('admin.audit:ops.octane,stop');
            });

        // Redis 实时监控（info/summary/命中率）：只读，需 ops.system.view。
        Route::prefix('redis')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/info', [RedisMonitorController::class, 'info']);
                Route::get('/summary', [RedisMonitorController::class, 'summary']);
                Route::get('/hit-rate', [RedisMonitorController::class, 'hitRate']);
            });

        // Redis 指标趋势（采样入库后的多天曲线）：只读，需 ops.system.view。
        Route::prefix('redis-metrics')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/push', [RedisMetricsController::class, 'push']);
                Route::get('/chart', [RedisMetricsController::class, 'chart']);
                Route::get('/trend', [RedisMetricsController::class, 'trend']);
            });

        // 队列监控（积压/失败等汇总）：只读，需 ops.system.view。
        Route::prefix('queue')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/summary', [QueueController::class, 'summary']);
            });

        // Supervisor 进程守护：查看类需 ops.supervisor.view；start/stop/restart/reread/update 等控制类
        // 需 ops.supervisor.control 且逐一记审计（直接影响线上进程，权限与审计分离）。
        Route::prefix('supervisor')->group(function (): void {
            Route::get('/status', [SupervisorController::class, 'status'])
                ->middleware('admin.permission:ops.supervisor.view');
            Route::get('/tail/{name}', [SupervisorController::class, 'tail'])
                ->middleware('admin.permission:ops.supervisor.view');
            Route::get('/logs/{name}', [SupervisorController::class, 'logs'])
                ->middleware('admin.permission:ops.supervisor.view');
            Route::post('/start/{name}', [SupervisorController::class, 'start'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,start']);
            Route::post('/stop/{name}', [SupervisorController::class, 'stop'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,stop']);
            Route::post('/restart/{name}', [SupervisorController::class, 'restart'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,restart']);
            Route::post('/reread', [SupervisorController::class, 'reread'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,reread']);
            Route::post('/update', [SupervisorController::class, 'update'])
                ->middleware(['admin.permission:ops.supervisor.control', 'admin.audit:ops.supervisor,update']);
        });

        // Docker 容器管控：查看类需 ops.docker.view；start/stop/restart 控制类需 ops.docker.control 且记审计。
        Route::prefix('docker')->group(function (): void {
            Route::get('/summary', [DockerController::class, 'summary'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/containers', [DockerController::class, 'containers'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/stats/{id}', [DockerController::class, 'stats'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/logs/{id}', [DockerController::class, 'logs'])
                ->middleware('admin.permission:ops.docker.view');
            Route::get('/version', [DockerController::class, 'version'])
                ->middleware('admin.permission:ops.docker.view');
            Route::post('/restart/{id}', [DockerController::class, 'restart'])
                ->middleware(['admin.permission:ops.docker.control', 'admin.audit:ops.docker,restart']);
            Route::post('/start/{id}', [DockerController::class, 'start'])
                ->middleware(['admin.permission:ops.docker.control', 'admin.audit:ops.docker,start']);
            Route::post('/stop/{id}', [DockerController::class, 'stop'])
                ->middleware(['admin.permission:ops.docker.control', 'admin.audit:ops.docker,stop']);
        });

        // 系统监控（CPU/内存/磁盘/指标趋势/高级信息）：只读，需 ops.system.view。
        Route::prefix('system')
            ->middleware('admin.permission:ops.system.view')
            ->group(function (): void {
                Route::get('/summary', [SystemMonitorController::class, 'summary']);
                Route::get('/metrics-trend', [SystemMonitorController::class, 'metricsTrend']);
                Route::get('/advanced', [AdvancedSystemController::class, 'summary']);
                Route::get('/disk', [DiskController::class, 'index']);
                Route::get('/disk/push', [DiskPushController::class, 'push']); // 主动采集/推送一次磁盘指标
            });

        // 网络监控：只读，需 ops.system.view。
        Route::get('/network', [NetworkController::class, 'index'])
            ->middleware('admin.permission:ops.system.view');

        // 告警中心（Ops Center 核心）：整组默认需 ops.alerts.view（只读查询）；
        // 任何写/处置类操作（确认、指派、恢复、静默、值班、规则、预设、批处理、评估、测试通知等）
        // 都额外叠加 ops.alerts.manage 权限 + admin.audit 审计。GET 查询类不记审计。
        Route::prefix('alerts')
            ->middleware('admin.permission:ops.alerts.view')
            ->group(function (): void {
                Route::get('/', [AlertController::class, 'index']);
                Route::get('/summary', [AlertController::class, 'summary']);
                Route::get('/assignees', [AlertController::class, 'assignees']);
                Route::get('/groups', [AlertController::class, 'groups']);
                // 批量处置：一次对多条告警确认/指派/静默（写操作，需 manage 权限 + 记审计）。
                Route::post('/batch/acknowledge', [AlertController::class, 'batchAcknowledge'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,batch_acknowledge']);
                Route::post('/batch/assign', [AlertController::class, 'batchAssign'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,batch_assign']);
                Route::post('/batch/silence', [AlertController::class, 'batchSilence'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,batch_silence']);
                Route::get('/notification-status', [AlertController::class, 'notificationStatus']);
                Route::get('/settings', [AlertController::class, 'settings']);
                Route::put('/settings', [AlertController::class, 'updateSettings'])
                    ->middleware(['admin.audit:ops.alerts,settings_update', 'admin.permission:ops.alerts.manage']);
                Route::get('/evaluations/latest', [AlertController::class, 'latestEvaluation']);
                Route::get('/trend', [AlertController::class, 'trend']);
                Route::get('/sla', [AlertController::class, 'sla']);
                Route::get('/report', [AlertController::class, 'report']);
                Route::get('/heatmap', [AlertController::class, 'heatmap']);
                Route::get('/workload', [AlertController::class, 'workload']);
                Route::get('/topology', [AlertController::class, 'topology']);
                Route::get('/handovers', [ShiftHandoverController::class, 'index']);
                Route::post('/handovers', [ShiftHandoverController::class, 'store'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,handover_create']);
                Route::post('/{alert}/tags', [AlertController::class, 'setTags'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,tags_update']);
                Route::get('/{alert}/similar', [AlertController::class, 'similar']);
                Route::get('/{alert}/notes', [AlertNoteController::class, 'index']);
                Route::post('/{alert}/notes', [AlertNoteController::class, 'store'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,note_create']);
                Route::delete('/{alert}/notes/{note}', [AlertNoteController::class, 'destroy'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,note_delete']);
                Route::get('/silences', [AlertSilenceController::class, 'index']);
                Route::post('/silences', [AlertSilenceController::class, 'store'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,silence_create']);
                Route::patch('/silences/{silence}', [AlertSilenceController::class, 'update'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,silence_toggle']);
                Route::delete('/silences/{silence}', [AlertSilenceController::class, 'destroy'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,silence_delete']);
                // 值班排班（on-call）：查看只读；班次增删改需 manage 权限 + 记审计。dashboard 为值班总览只读。
                Route::get('/on-call/dashboard', [OnCallDashboardController::class, 'overview'])
                    ->middleware('admin.permission:ops.alerts.view');
                Route::get('/on-call', [OnCallController::class, 'index']);
                Route::post('/on-call', [OnCallController::class, 'store'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,on_call_create']);
                Route::patch('/on-call/{shift}', [OnCallController::class, 'update'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,on_call_toggle']);
                Route::delete('/on-call/{shift}', [OnCallController::class, 'destroy'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,on_call_delete']);
                Route::get('/presets', [AlertPresetController::class, 'index']);
                Route::post('/presets', [AlertPresetController::class, 'store'])
                    ->middleware('admin.audit:ops.alerts,preset_create');
                Route::delete('/presets/{preset}', [AlertPresetController::class, 'destroy'])
                    ->middleware('admin.audit:ops.alerts,preset_delete');
                // 告警规则引擎：列表/导出/变更历史为只读；import/update/toggle 修改规则，需 manage 权限 + 记审计。
                // 注意 /rules/export 与 /rules/changes 定义在 /rules/{adminRule} 之前，避免被通配路由参数吞掉。
                Route::get('/rules', [AlertRuleController::class, 'index']);
                Route::get('/rules/export', [AlertRuleController::class, 'export']);
                Route::get('/rules/changes', [AlertRuleController::class, 'changes']);
                Route::post('/rules/import', [AlertRuleController::class, 'import'])
                    ->middleware(['admin.audit:ops.alerts,rule_import', 'admin.permission:ops.alerts.manage']);
                Route::put('/rules/{adminRule}', [AlertRuleController::class, 'update'])
                    ->middleware(['admin.audit:ops.alerts,rule_update', 'admin.permission:ops.alerts.manage']);
                Route::post('/rules/{adminRule}/toggle', [AlertRuleController::class, 'toggle'])
                    ->middleware(['admin.audit:ops.alerts,rule_toggle', 'admin.permission:ops.alerts.manage']);
                // 手动运维动作：立即评估告警、发测试通知、跑通道健康检查、注入演示场景（均写操作，manage + 审计）。
                Route::post('/evaluate', [AlertController::class, 'evaluate'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,evaluate']);
                Route::post('/test-notification', [AlertController::class, 'testNotification'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,test_notification']);
                Route::post('/health-check', [AlertController::class, 'runHealthCheck'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,health_check']);
                Route::post('/demo-scenarios', [AlertController::class, 'demoScenarios'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,demo_scenarios']);
                // 单条告警生命周期处置：确认 / 指派 / 恢复（均写操作，manage 权限 + 记审计）。放在末尾，
                // 让上面更具体的静态路径（/summary、/rules/... 等）先匹配，避免被 /{alert} 通配抢占。
                Route::post('/{alert}/acknowledge', [AlertController::class, 'acknowledge'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,acknowledge']);
                Route::post('/{alert}/assign', [AlertController::class, 'assign'])
                    ->middleware(['admin.audit:ops.alerts,assign', 'admin.permission:ops.alerts.manage']);
                Route::post('/{alert}/resolve', [AlertController::class, 'resolve'])
                    ->middleware(['admin.permission:ops.alerts.manage', 'admin.audit:ops.alerts,resolve']);
            });

        // 日志中心：需 ops.logs.view。每次查看/下载都记审计（读日志本身是敏感操作，便于追溯谁看了什么）。
        // 前端只能传预定义的日志 key（laravel/octane/redis/system/docker），不能传任意文件路径，
        // 白名单在 config/ops.php 的 logs.system_sources 里，防止读取服务器任意文件。
        Route::prefix('logs')
            ->middleware('admin.permission:ops.logs.view')
            ->group(function (): void {
                Route::get('/laravel', [LogController::class, 'laravel'])
                    ->middleware('admin.audit:ops.logs,laravel');
                Route::get('/laravel/download', [LogController::class, 'downloadLaravel'])
                    ->middleware('admin.audit:ops.logs,download');
                Route::get('/octane', [LogController::class, 'octane'])
                    ->middleware('admin.audit:ops.logs,octane');
                Route::get('/octane/download', [LogController::class, 'downloadOctane'])
                    ->middleware('admin.audit:ops.logs,download');
                Route::get('/redis', [LogController::class, 'redis'])
                    ->middleware('admin.audit:ops.logs,redis');
                Route::get('/redis/download', [LogController::class, 'downloadRedis'])
                    ->middleware('admin.audit:ops.logs,download');
                Route::get('/system', [LogController::class, 'system'])
                    ->middleware('admin.audit:ops.logs,system');
                Route::get('/system/download', [LogController::class, 'downloadSystem'])
                    ->middleware('admin.audit:ops.logs,download');
                Route::get('/system/sources', [LogController::class, 'systemSources'])
                    ->middleware('admin.audit:ops.logs,system_sources');
                Route::get('/docker', [LogController::class, 'docker'])
                    ->middleware('admin.audit:ops.logs,docker');
                Route::get('/docker/download', [LogController::class, 'downloadDocker'])
                    ->middleware('admin.audit:ops.logs,download');
            });

        // 广播联调用：手动触发一条 WebSocket 测试事件，验证 Reverb 推送链路是否通。需 ops.system.view。
        Route::get('/test-broadcast', function () {
            broadcast(new TestEvent([
                'hello' => 'world',
                'time' => now()->toDateTimeString(),
            ]));

            return [
                'success' => true,
            ];
        })->middleware('admin.permission:ops.system.view');
    });

// ===== 组外路由（故意不放进 /ops 组）=====
// 以下两条供“外部系统”调用，因此不能走后台会话认证（admin.auth）——外部无 cookie/会话。
// 改用控制器内的 token 守卫（?token= 或请求头，比对 config/ops.php 里的 metrics.token / ingest.token），
// 且默认 opt-in 关闭（对应 *_ENABLED=false 时直接 404/禁用）。放在 /ops 组之外正是为了不继承 admin.auth。

// Prometheus 指标导出（组外、无会话认证；控制器内 token 守卫 + opt-in）：
// GET /api/ops/metrics?token=... 输出文本格式指标，供 Prometheus 抓取。
Route::get('/ops/metrics', [MetricsController::class, 'index']);

// 入站 Webhook 告警（组外、URI 不以 api/ops/ 开头以绕开审计-POST 规则；控制器内 token 守卫 + opt-in）：
// 外部系统 POST /api/ingest/alerts 注入告警，复用现有去重/通知/广播管线。路径特意用 /ingest 而非 /ops/... ，
// 以避开对 api/ops POST 请求的自动审计规则（外部注入不应记后台操作审计）。
Route::post('/ingest/alerts', [AlertIngestController::class, 'store']);
