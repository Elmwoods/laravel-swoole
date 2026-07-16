<?php

namespace Tests\Feature\Ops;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpsReleaseCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_check_outputs_group_summary_in_testing_environment(): void
    {
        $this->createActiveSuperAdmin();

        $this->artisan('ops:release-check')
            ->expectsOutputToContain('Ops Center 发布自检')
            ->expectsOutputToContain('环境')
            ->expectsOutputToContain('数据库')
            ->expectsOutputToContain('后台安全')
            ->assertExitCode(0);
    }

    public function test_release_check_json_output_is_machine_readable_and_sanitized(): void
    {
        $this->createActiveSuperAdmin();

        $this->assertSame(0, Artisan::call('ops:release-check', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains($payload['status'], ['pass', 'warn', 'fail']);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertNotEmpty($payload['checks']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', strtolower($json));
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertStringNotContainsString('cookie', strtolower($json));
        $this->assertStringNotContainsString('private key', strtolower($json));
    }

    public function test_release_check_strict_fails_without_enabled_super_admin(): void
    {
        app(AdminPermissionRegistry::class)->syncDefaults();

        $this->artisan('ops:release-check', ['--strict' => true])
            ->expectsOutputToContain('缺少启用的超级管理员')
            ->assertExitCode(1);
    }

    public function test_release_check_flags_production_debug_as_failure(): void
    {
        $this->createActiveSuperAdmin();
        config()->set('app.env', 'production');
        config()->set('app.debug', true);

        $this->artisan('ops:release-check', ['--strict' => true])
            ->expectsOutputToContain('生产环境不能开启调试模式')
            ->assertExitCode(1);
    }

    public function test_release_check_warns_when_system_log_sources_are_empty(): void
    {
        $this->createActiveSuperAdmin();
        config()->set('ops.logs.system_sources', []);

        $this->artisan('ops:release-check')
            ->expectsOutputToContain('系统日志白名单为空')
            ->assertExitCode(0);
    }

    public function test_release_check_strict_fails_when_alert_settings_table_is_missing(): void
    {
        $this->createActiveSuperAdmin();

        try {
            Schema::dropIfExists('ops_alert_settings');

            $this->artisan('ops:release-check', ['--strict' => true])
                ->expectsOutputToContain('Table ops_alert_settings')
                ->assertExitCode(1);
        } finally {
            $this->restoreOpsAlertSettingsTable();
        }
    }

    public function test_release_check_strict_fails_when_database_cache_locks_table_is_missing(): void
    {
        $this->createActiveSuperAdmin();
        config()->set('cache.default', 'database');

        try {
            Schema::dropIfExists('cache_locks');

            $this->artisan('ops:release-check', ['--strict' => true])
                ->expectsOutputToContain('Table cache_locks')
                ->assertExitCode(1);
        } finally {
            $this->restoreCacheLocksTable();
        }
    }

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
