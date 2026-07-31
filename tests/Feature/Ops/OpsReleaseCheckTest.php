<?php

namespace Tests\Feature\Ops;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ops:release-check 命令测试（Ops Center 发布自检）。
 *
 * 该命令在发布前对环境、数据库、后台安全等分组进行自检，输出分组摘要或机器可读的 JSON。
 * 覆盖场景：测试环境下正常输出分组摘要、--json 输出可解析且不泄露敏感字段、
 * --strict 严格模式在缺少启用的超管 / 生产开启 debug / 关键表缺失时失败、
 * 以及系统日志白名单为空时仅告警（不失败）。
 */
class OpsReleaseCheckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证测试环境下命令输出各分组自检摘要：
     * 输出应包含标题及"环境/数据库/后台安全"等分组名，并以退出码 0 成功。
     */
    public function test_release_check_outputs_group_summary_in_testing_environment(): void
    {
        // 预置一个启用的超级管理员，使"后台安全"分组检查通过
        $this->createActiveSuperAdmin();

        $this->artisan('ops:release-check')
            ->expectsOutputToContain('Ops Center 发布自检')
            ->expectsOutputToContain('环境')
            ->expectsOutputToContain('数据库')
            ->expectsOutputToContain('后台安全')
            ->assertExitCode(0);
    }

    /**
     * 验证 --json 输出既机器可读又已脱敏：
     * 返回码为 0，payload 含 status(pass/warn/fail)、summary、非空 checks；
     * 且整段 JSON 不含 secret/password/token/cookie/private key 等敏感词，防止自检结果泄露凭据。
     */
    public function test_release_check_json_output_is_machine_readable_and_sanitized(): void
    {
        $this->createActiveSuperAdmin();

        // 用 Artisan::call 捕获输出，便于把 stdout 当作 JSON 解析
        $this->assertSame(0, Artisan::call('ops:release-check', ['--json' => true]));

        // 严格解析 JSON（JSON_THROW_ON_ERROR 保证非法 JSON 直接抛错）
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        // 顶层状态必须是三种合法值之一
        $this->assertContains($payload['status'], ['pass', 'warn', 'fail']);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertNotEmpty($payload['checks']);

        // 将结果重新序列化并转小写后逐一断言不含敏感关键字，确认脱敏到位
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', strtolower($json));
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertStringNotContainsString('cookie', strtolower($json));
        $this->assertStringNotContainsString('private key', strtolower($json));
    }

    /**
     * 验证严格模式在缺少启用的超级管理员时失败：
     * 只同步默认权限但不创建超管，--strict 下应输出"缺少启用的超级管理员"并以退出码 1 失败。
     */
    public function test_release_check_strict_fails_without_enabled_super_admin(): void
    {
        // 仅同步默认权限/角色，但刻意不创建任何超级管理员，触发后台安全检查失败
        app(AdminPermissionRegistry::class)->syncDefaults();

        $this->artisan('ops:release-check', ['--strict' => true])
            ->expectsOutputToContain('缺少启用的超级管理员')
            ->assertExitCode(1);
    }

    /**
     * 验证严格模式将"生产环境开启 debug"判为失败：
     * 把环境改为 production 且 debug=true，这是危险配置，--strict 下应失败并给出对应提示。
     */
    public function test_release_check_flags_production_debug_as_failure(): void
    {
        $this->createActiveSuperAdmin();
        // 模拟生产环境
        config()->set('app.env', 'production');
        // 生产环境开启调试模式属高危配置，应被自检拦截
        config()->set('app.debug', true);

        $this->artisan('ops:release-check', ['--strict' => true])
            ->expectsOutputToContain('生产环境不能开启调试模式')
            ->assertExitCode(1);
    }

    /**
     * 验证系统日志白名单为空时仅告警而不失败：
     * 将 ops.logs.system_sources 置空，非严格模式下输出"系统日志白名单为空"但退出码仍为 0。
     */
    public function test_release_check_warns_when_system_log_sources_are_empty(): void
    {
        $this->createActiveSuperAdmin();
        // 清空系统日志白名单，制造一个"警告级"（非致命）问题
        config()->set('ops.logs.system_sources', []);

        $this->artisan('ops:release-check')
            ->expectsOutputToContain('系统日志白名单为空')
            ->assertExitCode(0);
    }

    /**
     * 验证严格模式在告警设置表缺失时失败：
     * 删除 ops_alert_settings 表后，--strict 下应报告该表缺失并以退出码 1 失败；
     * finally 中恢复该表，避免污染后续用例。
     */
    public function test_release_check_strict_fails_when_alert_settings_table_is_missing(): void
    {
        $this->createActiveSuperAdmin();

        try {
            // 故意删除告警设置表，模拟迁移缺失
            Schema::dropIfExists('ops_alert_settings');

            $this->artisan('ops:release-check', ['--strict' => true])
                ->expectsOutputToContain('Table ops_alert_settings')
                ->assertExitCode(1);
        } finally {
            // 恢复被删的表，保证测试隔离
            $this->restoreOpsAlertSettingsTable();
        }
    }

    /**
     * 验证严格模式在使用 database 缓存驱动但 cache_locks 表缺失时失败：
     * 将缓存驱动切为 database 并删除 cache_locks 表，--strict 下应报告该表缺失并失败；
     * finally 中恢复该表。
     */
    public function test_release_check_strict_fails_when_database_cache_locks_table_is_missing(): void
    {
        $this->createActiveSuperAdmin();
        // 切换到 database 缓存驱动，使 cache_locks 表成为必需依赖
        config()->set('cache.default', 'database');

        try {
            // 删除锁表，模拟数据库缓存所需表结构缺失
            Schema::dropIfExists('cache_locks');

            $this->artisan('ops:release-check', ['--strict' => true])
                ->expectsOutputToContain('Table cache_locks')
                ->assertExitCode(1);
        } finally {
            // 恢复锁表，保证测试隔离
            $this->restoreCacheLocksTable();
        }
    }

    /**
     * 测试辅助方法：同步默认权限后创建一个启用状态的超级管理员并赋予 super_admin 角色。
     * 供各用例满足"存在启用超管"这一后台安全前置条件。
     */
    private function createActiveSuperAdmin(): AdminUser
    {
        app(AdminPermissionRegistry::class)->syncDefaults();

        $admin = AdminUser::query()->create([
            'name' => 'Release Admin',
            'email' => 'release-admin@example.com',
            'password' => Hash::make('release-password'),
            'is_active' => true,
        ]);
        $role = AdminRole::query()->where('slug', 'super_admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin->refresh();
    }

    /**
     * 测试辅助方法：若 ops_alert_settings 表不存在则重建，用于用例结束后恢复表结构。
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

    /**
     * 测试辅助方法：若 cache_locks 表不存在则重建，用于用例结束后恢复表结构。
     */
    private function restoreCacheLocksTable(): void
    {
        if (Schema::hasTable('cache_locks')) {
            return;
        }

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }
}
