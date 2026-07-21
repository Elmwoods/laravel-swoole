<?php

namespace Tests\Feature\Admin;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_two_factor_for_the_given_email_including_super_admin(): void
    {
        $admin = $this->createAdminWithTwoFactor();
        $superRole = AdminRole::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => '超级管理员', 'is_active' => true, 'is_system' => true],
        );
        $admin->roles()->syncWithoutDetaching([$superRole->id]);

        $this->assertTrue($admin->twoFactorEnabled());
        $originalVersion = (int) $admin->session_version;

        $this->artisan('admin:reset-two-factor', ['--email' => $admin->email])
            ->assertExitCode(0);

        $admin->refresh();
        $this->assertFalse($admin->twoFactorEnabled());
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_last_used_step);
        $this->assertSame($originalVersion + 1, (int) $admin->session_version);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.users',
            'action' => 'two_factor_reset_cli',
            'result' => 'success',
        ]);
    }

    public function test_it_matches_email_case_insensitively(): void
    {
        $admin = $this->createAdminWithTwoFactor(['email' => 'Owner@Example.com']);

        $this->artisan('admin:reset-two-factor', ['--email' => 'owner@example.com'])
            ->assertExitCode(0);

        $this->assertFalse($admin->refresh()->twoFactorEnabled());
    }

    public function test_it_fails_for_unknown_email(): void
    {
        $this->artisan('admin:reset-two-factor', ['--email' => 'missing@example.com'])
            ->assertExitCode(1);

        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'two_factor_reset_cli',
        ]);
    }

    public function test_it_fails_for_invalid_email(): void
    {
        $this->artisan('admin:reset-two-factor', ['--email' => 'not-an-email'])
            ->assertExitCode(1);
    }

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
