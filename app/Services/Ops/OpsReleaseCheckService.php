<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OpsReleaseCheckService
{
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'cookie',
        'private_key',
        'authorization',
    ];

    public function run(): array
    {
        $checks = array_merge(
            $this->environmentChecks(),
            $this->databaseChecks(),
            $this->adminSecurityChecks(),
            $this->rbacChecks(),
            $this->logCenterChecks(),
            $this->alertChecks(),
            $this->queueAndScheduleChecks(),
            $this->documentChecks(),
        );

        return [
            'status' => $this->aggregateStatus($checks),
            'summary' => $this->summarize($checks),
            'checks' => $this->sanitize($checks),
        ];
    }

    public function aggregateStatus(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');

        if ($statuses->contains('fail')) {
            return 'fail';
        }

        if ($statuses->contains('warn')) {
            return 'warn';
        }

        return 'pass';
    }

    public function summarize(array $checks): array
    {
        return [
            'pass' => collect($checks)->where('status', 'pass')->count(),
            'warn' => collect($checks)->where('status', 'warn')->count(),
            'fail' => collect($checks)->where('status', 'fail')->count(),
        ];
    }

    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = mb_strimwidth($value, 0, 500, '...');

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function environmentChecks(): array
    {
        $env = (string) config('app.env', 'unknown');
        $debug = (bool) config('app.debug');
        $checks = [
            $this->check('环境', 'APP_ENV', $env !== '', 'pass', "当前环境：{$env}", '确认生产发布时为 production。'),
            $this->check('环境', 'APP_KEY', (string) config('app.key') !== '', 'pass', '应用密钥已配置。', '缺失时执行 php artisan key:generate。'),
            $this->debugCheck($env, $debug),
            $this->check('环境', 'Cache Driver', true, 'pass', '缓存驱动：'.(string) config('cache.default'), '确认生产缓存驱动符合部署预期。'),
            $this->check('环境', 'Session Driver', true, 'pass', '会话驱动：'.(string) config('session.driver'), '确认后台会话驱动支持生产并发。'),
            $this->check('环境', 'Queue Driver', true, 'pass', '队列驱动：'.(string) config('queue.default'), '确认 worker 已随发布启动。'),
            $this->check('环境', 'Octane Server', true, 'pass', 'Octane Server：'.(string) config('octane.server', 'swoole'), '发布后重启 Octane/Swoole。'),
        ];

        return $checks;
    }

    private function databaseChecks(): array
    {
        try {
            DB::connection()->getPdo();
            $connected = true;
        } catch (Throwable) {
            $connected = false;
        }

        $checks = [
            $this->check('数据库', 'Database Connection', $connected, 'fail', $connected ? '数据库连接正常。' : '数据库连接失败。', '检查 DB_HOST、DB_DATABASE、DB_USERNAME 和网络连通性。'),
        ];

        foreach ([
            'admin_users',
            'admin_roles',
            'admin_permissions',
            'admin_audit_logs',
            'ops_alerts',
            'ops_alert_rules',
            'ops_alert_evaluations',
            'ops_alert_events',
            'ops_alert_settings',
            'ops_release_checks',
            'ops_inspections',
            'cache',
        ] as $table) {
            $exists = $connected && Schema::hasTable($table);
            $checks[] = $this->check('数据库', "Table {$table}", $exists, 'fail', $exists ? "表 {$table} 存在。" : "表 {$table} 缺失。", '执行 php artisan migrate。');
        }

        if ((string) config('cache.default') === 'database') {
            $cacheLocksExists = $connected && Schema::hasTable('cache_locks');
            $checks[] = $this->check(
                '数据库',
                'Table cache_locks',
                $cacheLocksExists,
                'fail',
                $cacheLocksExists ? '表 cache_locks 存在。' : '数据库缓存锁表 cache_locks 缺失。',
                '执行 php artisan migrate，或生产环境改用 Redis cache lock。',
            );
        }

        $migrationsReadable = $connected && Schema::hasTable('migrations');
        $checks[] = $this->check('数据库', 'Migrations', $migrationsReadable, 'fail', $migrationsReadable ? '迁移表可读取。' : '迁移表不可读取。', '执行 php artisan migrate:status 排查。');

        return $checks;
    }

    private function debugCheck(string $env, bool $debug): array
    {
        if ($env === 'production' && $debug) {
            return $this->check('环境', 'APP_DEBUG', false, 'fail', '生产环境不能开启调试模式。', '生产环境设置 APP_DEBUG=false。');
        }

        if ($debug) {
            return $this->check('环境', 'APP_DEBUG', false, 'warn', '调试模式已开启，仅建议用于非生产环境。', '生产环境设置 APP_DEBUG=false。');
        }

        return $this->check('环境', 'APP_DEBUG', true, 'fail', '调试模式已关闭。', '生产环境设置 APP_DEBUG=false。');
    }

    private function adminSecurityChecks(): array
    {
        $superAdminExists = Schema::hasTable('admin_users')
            && AdminUser::query()
                ->where('is_active', true)
                ->whereHas('activeRoles', fn ($query) => $query->where('slug', 'super_admin'))
                ->exists();

        $checks = [
            $this->check('后台安全', 'Super Admin', $superAdminExists, 'fail', $superAdminExists ? '已存在启用的超级管理员。' : '缺少启用的超级管理员。', '执行 admin:create-super 创建首个超级管理员。'),
        ];

        try {
            $payload = app(AdminPasswordCryptoService::class)->publicKeyPayload();
            $cryptoReady = isset($payload['key_id'], $payload['public_key']);
        } catch (Throwable) {
            $cryptoReady = false;
        }

        $checks[] = $this->check('后台安全', 'Admin Crypto Public Key', $cryptoReady, 'fail', $cryptoReady ? '后台登录加密公钥可生成。' : '后台登录加密公钥不可用。', '检查后台 RSA 登录密钥配置。');
        $checks[] = $this->check('后台安全', 'Audit Table', Schema::hasTable('admin_audit_logs'), 'fail', Schema::hasTable('admin_audit_logs') ? '审计日志表存在。' : '审计日志表缺失。', '执行 php artisan migrate。');

        return $checks;
    }

    private function rbacChecks(): array
    {
        try {
            app(AdminPermissionRegistry::class)->syncDefaults();
            $ready = true;
        } catch (Throwable) {
            $ready = false;
        }

        return [
            $this->check('权限/RBAC', 'Permission Registry', $ready, 'fail', $ready ? '权限白名单和内置角色可同步。' : '权限白名单同步失败。', '检查 admin_permissions、admin_roles 和 pivot 表。'),
        ];
    }

    private function logCenterChecks(): array
    {
        $sources = (array) config('ops.logs.system_sources', []);

        return [
            $this->check('日志中心', 'System Log Sources', $sources !== [], 'warn', $sources !== [] ? '系统日志白名单已配置。' : '系统日志白名单为空。', '配置 ops.logs.system_sources 后再发布日志中心。'),
            $this->check('日志中心', 'Log Download Routes', $this->routesExist([
                'api/ops/logs/laravel/download',
                'api/ops/logs/octane/download',
                'api/ops/logs/redis/download',
                'api/ops/logs/system/download',
                'api/ops/logs/docker/download',
            ]), 'fail', '日志下载路由已注册。', '执行 route:list --path=ops 检查日志下载路由。'),
        ];
    }

    private function alertChecks(): array
    {
        try {
            $rules = app(AlertRuleRegistryService::class)->syncDefaults();
            $rulesReady = $rules->isNotEmpty();
        } catch (Throwable) {
            $rulesReady = false;
        }

        return [
            $this->check('告警中心', 'Alert Rules', $rulesReady, 'fail', $rulesReady ? '默认告警规则可同步。' : '默认告警规则同步失败。', '检查 ops_alert_rules 表并执行 migrate。'),
            $this->check('告警中心', 'Evaluate Command', array_key_exists('ops:alerts:evaluate', Artisan::all()), 'fail', '告警评估命令已注册。', '确认 app/Console/Commands/Ops/EvaluateAlertsCommand.php 可被加载。'),
        ];
    }

    private function queueAndScheduleChecks(): array
    {
        return [
            $this->check('队列/调度', 'Queue Names', config('ops.queues.names', []) !== [], 'warn', '队列名称已配置。', '配置 OPS_QUEUE_NAMES，至少包含 default。'),
            $this->check('队列/调度', 'Console Schedule', is_file(base_path('routes/console.php')), 'fail', '调度入口文件存在。', '确认 routes/console.php 随代码发布。'),
        ];
    }

    private function documentChecks(): array
    {
        return collect([
            'docs/ops-center-phase-5-release.md',
            'docs/ops-center-phase-6-release.md',
            'docs/ops-center-phase-7-release.md',
            'docs/ops-center-phase-8.md',
            'docs/ops-center-phase-9.md',
            'docs/ops-center-phase-10.md',
            'docs/ops-center-phase-11.md',
            'docs/ops-center-phase-12.md',
            'docs/ops-center-phase-13.md',
            'docs/ops-center-phase-14.md',
            'docs/ops-center-phase-15.md',
            'docs/ops-center-phase-16.md',
            'docs/ops-center-phase-17.md',
            'docs/ops-center-phase-18.md',
            'docs/ops-center-phase-19.md',
            'docs/ops-center-phase-20.md',
            'docs/ops-center-phase-21.md',
            'docs/ops-center-phase-22.md',
            'docs/ops-center-phase-23.md',
            'docs/ops-center-phase-24.md',
            'docs/ops-center-phase-25.md',
            'docs/ops-center-phase-26.md',
            'docs/ops-center-phase-27.md',
            'docs/ops-center-deploy-runbook.md',
        ])
            ->map(fn (string $path): array => $this->check('发布文档', $path, is_file(base_path($path)), 'warn', is_file(base_path($path)) ? "{$path} 存在。" : "{$path} 缺失。", '补齐发布验收和回滚说明。'))
            ->all();
    }

    private function routesExist(array $uris): bool
    {
        $registered = collect(Route::getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        return array_diff($uris, $registered) === [];
    }

    private function check(string $group, string $name, bool $ok, string $failureStatus, string $message, string $hint): array
    {
        return [
            'group' => $group,
            'name' => $name,
            'status' => $ok ? 'pass' : $failureStatus,
            'message' => $message,
            'hint' => $hint,
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
