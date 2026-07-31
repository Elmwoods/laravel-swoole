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

/**
 * 发布自检服务：Ops Center 的“上线前体检清单”。
 *
 * 作用：从环境配置、数据库表、后台安全、RBAC 权限、日志中心、告警中心、队列/调度、
 * 发布文档八个维度逐项检查，聚合出 pass/warn/fail 结论，供人工发布前和巡检（full）调用。
 *
 * 「为什么」：每项检查落地为统一的 check 结构，失败严重度由 failureStatus 决定
 * （有的缺失是 fail 阻断上线，有的只是 warn 提示）；所有外部依赖（DB/加密/注册表）
 * 都用 try/catch 包裹，探测不可用即判失败而非抛异常，保证自检本身永远能跑完。
 */
class OpsReleaseCheckService
{
    // 需要整段过滤的敏感字段名（命中即替换为 [FILTERED]）
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'cookie',
        'private_key',
        'authorization',
    ];

    /**
     * 作用：跑完全部八类检查，返回整体状态、状态计数与脱敏后的逐项明细。
     *
     * @return array{status:string,summary:array{pass:int,warn:int,fail:int},checks:array} 自检结果
     */
    public function run(): array
    {
        $checks = array_merge(
            $this->environmentChecks(),       // 环境：APP_ENV/KEY/DEBUG/各驱动
            $this->databaseChecks(),          // 数据库：连接 + 关键表存在性
            $this->adminSecurityChecks(),     // 后台安全：超管/登录加密/审计表
            $this->rbacChecks(),              // 权限白名单可同步
            $this->logCenterChecks(),         // 日志中心：来源白名单 + 下载路由
            $this->alertChecks(),             // 告警中心：规则同步 + 评估命令
            $this->queueAndScheduleChecks(),  // 队列/调度：队列名 + 调度入口
            $this->documentChecks(),          // 发布文档齐全性
        );

        return [
            'status' => $this->aggregateStatus($checks),
            'summary' => $this->summarize($checks),
            'checks' => $this->sanitize($checks), // 明细脱敏后返回
        ];
    }

    /**
     * 作用：按“最严重优先”聚合整体状态（fail > warn > pass）。
     *
     * @param  array  $checks  check 列表
     * @return string 整体状态
     */
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

    /**
     * 作用：统计 checks 中各状态的数量。
     *
     * @param  array  $checks  check 列表
     * @return array{pass:int,warn:int,fail:int} 状态计数
     */
    public function summarize(array $checks): array
    {
        return [
            'pass' => collect($checks)->where('status', 'pass')->count(),
            'warn' => collect($checks)->where('status', 'warn')->count(),
            'fail' => collect($checks)->where('status', 'fail')->count(),
        ];
    }

    /**
     * 作用：递归脱敏结果数组：敏感键整段屏蔽、字符串截断、其余原样保留。
     *
     * @param  array  $payload  待脱敏数组（可嵌套）
     * @return array 脱敏后的数组
     *
     * 「为什么」：这里字符串只做长度截断（值本身多为环境/表名等非密），敏感信息靠敏感键屏蔽。
     */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            // 敏感键整段屏蔽
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[FILTERED]';

                continue;
            }

            // 数组递归下钻
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            // 字符串按显示宽度截断，防超长
            if (is_string($value)) {
                $sanitized[$key] = mb_strimwidth($value, 0, 500, '...');

                continue;
            }

            $sanitized[$key] = $value; // 数字/布尔等原样保留
        }

        return $sanitized;
    }

    /**
     * 作用：环境类检查——APP_ENV/APP_KEY/APP_DEBUG 及各运行驱动。
     *
     * @return array<int, array> 环境相关 check 列表
     *
     * 「为什么」：APP_DEBUG 需按环境区分严重度，故单独抽到 debugCheck；
     * 缓存/会话/队列/Octane 驱动仅回显当前值供人工核对，不判失败（都传 pass）。
     */
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

    /**
     * 作用：数据库类检查——连接可用性 + 一批关键表是否存在 + 迁移表可读。
     *
     * @return array<int, array> 数据库相关 check 列表
     *
     * 「为什么」：先探连接，连接失败则后续 hasTable 全部短路为 false（避免逐表报错刷屏）；
     * cache_locks 仅在缓存驱动为 database 时才检查（Redis 锁不需要该表）。
     */
    private function databaseChecks(): array
    {
        // 探测数据库连接：拿 PDO 不抛错即连通
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
            // 未连接时直接判缺失，避免对断连的库反复 hasTable
            $exists = $connected && Schema::hasTable($table);
            $checks[] = $this->check('数据库', "Table {$table}", $exists, 'fail', $exists ? "表 {$table} 存在。" : "表 {$table} 缺失。", '执行 php artisan migrate。');
        }

        // 仅当缓存驱动是 database 才需要 cache_locks 表（Redis 锁无需该表）
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

    /**
     * 作用：按环境判定 APP_DEBUG 的严重度。
     *
     * @param  string  $env  当前环境
     * @param  bool  $debug  是否开启调试
     * @return array 一条 APP_DEBUG check
     *
     * 「为什么」：production + debug 是硬故障（泄栈风险）判 fail；非生产开 debug 仅 warn；
     * debug 关闭时以 ok=true 传 pass（第 4 个 fail 参数在 ok 为真时不生效，故仍为 pass）。
     */
    private function debugCheck(string $env, bool $debug): array
    {
        // 生产环境开调试 = 严重故障，直接 fail
        if ($env === 'production' && $debug) {
            return $this->check('环境', 'APP_DEBUG', false, 'fail', '生产环境不能开启调试模式。', '生产环境设置 APP_DEBUG=false。');
        }

        // 非生产环境开调试 = 提醒级 warn
        if ($debug) {
            return $this->check('环境', 'APP_DEBUG', false, 'warn', '调试模式已开启，仅建议用于非生产环境。', '生产环境设置 APP_DEBUG=false。');
        }

        // 调试已关闭：ok=true → 结果为 pass（failureStatus 不生效）
        return $this->check('环境', 'APP_DEBUG', true, 'fail', '调试模式已关闭。', '生产环境设置 APP_DEBUG=false。');
    }

    /**
     * 作用：后台安全类检查——是否存在启用的超管、登录加密公钥可生成、审计表存在。
     *
     * @return array<int, array> 后台安全相关 check 列表
     *
     * 「为什么」：先判 admin_users 表存在再查超管，避免表缺失时查询报错；
     * 加密公钥用 try/catch 探测生成能力，任何异常都视为“不可用”而非中断自检。
     */
    private function adminSecurityChecks(): array
    {
        // 先确认表存在再查询，防止表缺失导致查询异常
        $superAdminExists = Schema::hasTable('admin_users')
            && AdminUser::query()
                ->where('is_active', true)
                ->whereHas('activeRoles', fn ($query) => $query->where('slug', 'super_admin'))
                ->exists();

        $checks = [
            $this->check('后台安全', 'Super Admin', $superAdminExists, 'fail', $superAdminExists ? '已存在启用的超级管理员。' : '缺少启用的超级管理员。', '执行 admin:create-super 创建首个超级管理员。'),
        ];

        // 探测后台登录 RSA 公钥能否生成：缺 key_id/public_key 或抛错都判不可用
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

    /**
     * 作用：RBAC 检查——权限白名单与内置角色能否同步。
     *
     * @return array<int, array> 单条 Permission Registry check
     *
     * 「为什么」：直接尝试 syncDefaults()，能跑通即说明 permissions/roles/pivot 表结构就绪；
     * 任何异常判失败，避免上线后权限体系不可用。
     */
    private function rbacChecks(): array
    {
        // 试跑一次默认同步，成功即视为权限体系就绪
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

    /**
     * 作用：日志中心检查——系统日志来源白名单是否配置、日志下载路由是否全部注册。
     *
     * @return array<int, array> 日志中心相关 check 列表
     *
     * 「为什么」：白名单为空只 warn（功能可用但无来源）；下载路由缺失是 fail（前端按钮会 404）。
     */
    private function logCenterChecks(): array
    {
        $sources = (array) config('ops.logs.system_sources', []);

        return [
            // 白名单为空仅提示（warn），不阻断上线
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

    /**
     * 作用：告警中心检查——默认告警规则可同步、评估命令已注册。
     *
     * @return array<int, array> 告警中心相关 check 列表
     *
     * 「为什么」：同步返回空集合（isNotEmpty 为假）也算失败，说明规则未落库；
     * 评估命令是定时告警的入口，未注册则整套告警形同虚设，故判 fail。
     */
    private function alertChecks(): array
    {
        // 试同步默认规则：抛错或结果为空都视为未就绪
        try {
            $rules = app(AlertRuleRegistryService::class)->syncDefaults();
            $rulesReady = $rules->isNotEmpty();
        } catch (Throwable) {
            $rulesReady = false;
        }

        return [
            $this->check('告警中心', 'Alert Rules', $rulesReady, 'fail', $rulesReady ? '默认告警规则可同步。' : '默认告警规则同步失败。', '检查 ops_alert_rules 表并执行 migrate。'),
            // 检查评估命令是否已在 Artisan 注册表里
            $this->check('告警中心', 'Evaluate Command', array_key_exists('ops:alerts:evaluate', Artisan::all()), 'fail', '告警评估命令已注册。', '确认 app/Console/Commands/Ops/EvaluateAlertsCommand.php 可被加载。'),
        ];
    }

    /**
     * 作用：队列/调度检查——队列名已配置、调度入口文件存在。
     *
     * @return array<int, array> 队列/调度相关 check 列表
     *
     * 「为什么」：队列名缺失仅 warn（可回落 default）；routes/console.php 缺失是 fail（所有定时任务失效）。
     */
    private function queueAndScheduleChecks(): array
    {
        return [
            $this->check('队列/调度', 'Queue Names', config('ops.queues.names', []) !== [], 'warn', '队列名称已配置。', '配置 OPS_QUEUE_NAMES，至少包含 default。'),
            // 调度入口文件缺失将导致所有 schedule 任务不生效
            $this->check('队列/调度', 'Console Schedule', is_file(base_path('routes/console.php')), 'fail', '调度入口文件存在。', '确认 routes/console.php 随代码发布。'),
        ];
    }

    /**
     * 作用：发布文档检查——逐个校验各阶段发布文档与部署 runbook 是否存在。
     *
     * @return array<int, array> 每个文档一条 check（缺失为 warn）
     *
     * 「为什么」：文档缺失只 warn（不阻断上线），但提示补齐验收/回滚说明；
     * 每新增一个 phase 需在此列表追加对应文档路径。
     */
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
            'docs/ops-center-phase-28.md',
            'docs/ops-center-phase-29.md',
            'docs/ops-center-phase-30.md',
            'docs/ops-center-phase-31.md',
            'docs/ops-center-phase-32.md',
            'docs/ops-center-phase-33.md',
            'docs/ops-center-phase-34.md',
            'docs/ops-center-phase-35.md',
            'docs/ops-center-phase-36.md',
            'docs/ops-center-phase-37.md',
            'docs/ops-center-phase-38.md',
            'docs/ops-center-phase-39.md',
            'docs/ops-center-deploy-runbook.md',
        ])
            ->map(fn (string $path): array => $this->check('发布文档', $path, is_file(base_path($path)), 'warn', is_file(base_path($path)) ? "{$path} 存在。" : "{$path} 缺失。", '补齐发布验收和回滚说明。'))
            ->all();
    }

    /**
     * 作用：判断给定 URI 列表是否全部已在路由表注册。
     *
     * @param  array  $uris  期望存在的路由 URI 列表
     * @return bool 全部注册返回 true，缺任意一个返回 false
     *
     * 「为什么」：用 array_diff（期望 - 已注册）为空来判定“全部命中”，比逐个 in_array 更简洁。
     */
    private function routesExist(array $uris): bool
    {
        $registered = collect(Route::getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        // 期望集合减去已注册集合为空 = 全部存在
        return array_diff($uris, $registered) === [];
    }

    /**
     * 作用：构造统一的 check 结构（本服务所有检查项的工厂方法）。
     *
     * @param  string  $group  分组名
     * @param  string  $name  检查名
     * @param  bool  $ok  是否通过
     * @param  string  $failureStatus  未通过时的状态（fail 或 warn，决定该项严重度）
     * @param  string  $message  结果描述
     * @param  string  $hint  修复建议
     * @return array 统一的 check 结构
     *
     * 「为什么」：把“通过=pass、未通过=各自 failureStatus”的三态逻辑收敛到一处，
     * 各检查只需声明自己失败时该算 warn 还是 fail。
     */
    private function check(string $group, string $name, bool $ok, string $failureStatus, string $message, string $hint): array
    {
        return [
            'group' => $group,
            'name' => $name,
            // 通过为 pass，否则取调用方指定的失败严重度
            'status' => $ok ? 'pass' : $failureStatus,
            'message' => $message,
            'hint' => $hint,
        ];
    }

    /**
     * 作用：判断某键名是否敏感（精确相等或包含敏感词）。
     *
     * @param  string  $key  键名
     * @return bool 是否敏感
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key); // 统一小写比对，忽略大小写

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
