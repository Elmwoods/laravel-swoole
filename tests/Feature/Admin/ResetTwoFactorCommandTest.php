<?php

namespace Tests\Feature\Admin;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * admin:reset-two-factor 命令测试（CLI 破窗 / break-glass 二次验证重置）。
 *
 * 该命令用于在管理员丢失 TOTP 设备时，通过命令行按邮箱重置其两步验证（2FA），
 * 即便目标是超级管理员也可重置。覆盖场景：成功重置并清空 2FA 相关字段、
 * 提升 session_version 使旧会话失效、写入审计日志；邮箱大小写不敏感匹配；
 * 未知邮箱与非法邮箱格式时失败且不写审计日志。
 */
class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证按邮箱成功重置 2FA（含超级管理员）：
     * 重置后 twoFactorEnabled 为 false，secret/confirmed_at/last_used_step 全部清空，
     * session_version 自增 1（使旧登录会话失效），并落地一条成功的审计日志。
     */
    public function test_it_resets_two_factor_for_the_given_email_including_super_admin(): void
    {
        $admin = $this->createAdminWithTwoFactor();
        // 赋予超级管理员角色，验证该命令对超管同样生效（break-glass 场景）
        $superRole = AdminRole::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => '超级管理员', 'is_active' => true, 'is_system' => true],
        );
        $admin->roles()->syncWithoutDetaching([$superRole->id]);

        // 前置断言：目标管理员当前确已启用 2FA
        $this->assertTrue($admin->twoFactorEnabled());
        // 记录原始 session_version，稍后校验其被 +1
        $originalVersion = (int) $admin->session_version;

        $this->artisan('admin:reset-two-factor', ['--email' => $admin->email])
            ->assertExitCode(0);

        $admin->refresh();
        // 2FA 已被关闭
        $this->assertFalse($admin->twoFactorEnabled());
        // 相关敏感字段被清空
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_last_used_step);
        // session_version 自增 1，强制原有会话失效
        $this->assertSame($originalVersion + 1, (int) $admin->session_version);

        // 应写入一条 CLI 重置的成功审计日志，便于追踪破窗操作
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.users',
            'action' => 'two_factor_reset_cli',
            'result' => 'success',
        ]);
    }

    /**
     * 验证邮箱匹配大小写不敏感：
     * 账号邮箱存为混合大小写（Owner@Example.com），用全小写（owner@example.com）传参仍能命中并重置成功。
     */
    public function test_it_matches_email_case_insensitively(): void
    {
        // 故意用混合大小写邮箱创建账号，以检验匹配时的大小写归一化
        $admin = $this->createAdminWithTwoFactor(['email' => 'Owner@Example.com']);

        $this->artisan('admin:reset-two-factor', ['--email' => 'owner@example.com'])
            ->assertExitCode(0);

        $this->assertFalse($admin->refresh()->twoFactorEnabled());
    }

    /**
     * 验证未知邮箱时命令失败：
     * 找不到对应管理员应返回退出码 1，且不产生任何 two_factor_reset_cli 审计日志。
     */
    public function test_it_fails_for_unknown_email(): void
    {
        $this->artisan('admin:reset-two-factor', ['--email' => 'missing@example.com'])
            ->assertExitCode(1);

        // 未命中账号时不应写入审计日志
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'two_factor_reset_cli',
        ]);
    }

    /**
     * 验证非法邮箱格式时命令失败：
     * 传入不符合邮箱格式的字符串（not-an-email）应在校验阶段被拒，返回退出码 1。
     */
    public function test_it_fails_for_invalid_email(): void
    {
        $this->artisan('admin:reset-two-factor', ['--email' => 'not-an-email'])
            ->assertExitCode(1);
    }

    /**
     * 测试辅助方法：创建一个已启用 2FA 的管理员。
     * 先建管理员账号（可通过 $overrides 覆盖字段，如邮箱），
     * 再借助 AdminTwoFactorService 启用两步验证（写入 secret 与恢复码，last_used_step 设为 100）。
     */
    private function createAdminWithTwoFactor(array $overrides = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'name' => 'Ops Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ], $overrides));

        $service = app(AdminTwoFactorService::class);
        $service->enable($admin, $service->generateSecret(), $service->generateRecoveryCodes(), 100);

        return $admin->refresh();
    }
}
