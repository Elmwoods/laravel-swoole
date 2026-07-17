<?php

namespace Tests\Feature\Ops;

use App\Models\AdminAuditLog;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseElevenTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfirmed_admin_must_setup_two_factor_before_full_login(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('data.requires_two_factor_setup', true)
            ->assertJsonStructure(['data' => ['setup' => ['secret', 'otpauth_uri']]])
            ->assertJsonMissingPath('data.admin')
            ->assertJsonMissingPath('data.setup.recovery_codes');

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401);

        $pendingSecret = session('admin_two_factor_pending_secret');
        $this->assertIsString($pendingSecret);

        $code = app(AdminTwoFactorService::class)->totpCode($pendingSecret);

        $response = $this->postJson('/api/admin/auth/two-factor/confirm', [
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('data.profile.admin.email', $admin->email)
            ->assertJsonPath('data.profile.security.two_factor_enabled', true)
            ->assertJsonStructure(['data' => ['profile', 'recovery_codes']])
            ->json('data');

        $this->assertCount(8, $response['recovery_codes']);
        $this->assertNotNull($admin->refresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.auth',
            'action' => 'two_factor_setup',
            'result' => 'success',
        ]);
    }

    public function test_confirm_rejects_wrong_totp_and_does_not_enable_two_factor(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        $this->postJson('/api/admin/auth/two-factor/confirm', [
            'code' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertNull($admin->refresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.auth',
            'action' => 'two_factor_setup',
            'result' => 'failure',
        ]);
    }

    public function test_confirm_requires_pending_password_session(): void
    {
        $this->postJson('/api/admin/auth/two-factor/confirm', [
            'code' => '123456',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', '二次验证会话已失效，请重新登录。');
    }

    public function test_confirm_payload_does_not_leak_secret_or_recovery_hashes(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        $pendingSecret = session('admin_two_factor_pending_secret');
        $response = $this->postJson('/api/admin/auth/two-factor/confirm', [
            'code' => app(AdminTwoFactorService::class)->totpCode($pendingSecret),
        ])->assertOk();

        $json = $response->getContent();
        $this->assertStringNotContainsString($pendingSecret, $json);
        $this->assertStringNotContainsString('two_factor_secret', $json);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $json);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'payload' => $pendingSecret,
        ]);
    }

    public function test_confirmed_admin_must_pass_two_factor_challenge_before_login(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        ['secret' => $secret] = $this->enableTwoFactor($admin);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('data.requires_two_factor', true)
            ->assertJsonMissingPath('data.admin');

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401);

        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'code' => app(AdminTwoFactorService::class)->totpCode($secret),
        ])
            ->assertOk()
            ->assertJsonPath('data.admin.email', $admin->email)
            ->assertJsonPath('data.security.two_factor_enabled', true);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.auth',
            'action' => 'two_factor_challenge',
            'result' => 'success',
        ]);
    }

    public function test_wrong_challenge_code_is_rejected_and_audited(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'code' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'module' => 'admin.auth',
            'action' => 'two_factor_challenge',
            'result' => 'failure',
        ]);
    }

    public function test_recovery_code_can_be_used_once_for_challenge(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        ['recovery_codes' => $recoveryCodes] = $this->enableTwoFactor($admin);
        $recoveryCode = $recoveryCodes[0];

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'recovery_code' => $recoveryCode,
        ])
            ->assertOk()
            ->assertJsonPath('data.admin.email', $admin->email);

        auth('admin')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'recovery_code' => $recoveryCode,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recovery_code']);
    }

    public function test_super_admin_can_reset_other_admin_two_factor_and_invalidate_sessions(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($target);

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['admin_session_version' => (int) $superAdmin->session_version])
            ->postJson("/api/admin/users/{$target->id}/two-factor/reset")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.security.two_factor_enabled', false);

        $target->refresh();
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertSame(2, (int) $target->session_version);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $superAdmin->id,
            'module' => 'admin.users',
            'action' => 'two_factor_reset',
            'result' => 'success',
        ]);
    }

    public function test_non_super_admin_and_self_reset_are_rejected(): void
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $target = $this->createAdmin(['ops.dashboard.view']);
        $superAdmin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_session_version' => (int) $admin->session_version])
            ->postJson("/api/admin/users/{$target->id}/two-factor/reset")
            ->assertStatus(403);

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['admin_session_version' => (int) $superAdmin->session_version])
            ->postJson("/api/admin/users/{$superAdmin->id}/two-factor/reset")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['admin_user']);
    }

    private function createAdmin(array $permissions, array $overrides = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'name' => 'Ops Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ], $overrides));

        $role = AdminRole::query()->create([
            'name' => '测试角色',
            'slug' => 'test-role-'.uniqid(),
            'description' => '测试角色',
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

        return $admin->refresh();
    }

    private function createSuperAdmin(): AdminUser
    {
        $admin = $this->createAdmin(['admin.users.manage']);
        $superRole = AdminRole::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => '超级管理员', 'is_active' => true, 'is_system' => true],
        );
        $admin->roles()->syncWithoutDetaching([$superRole->id]);

        return $admin->refresh();
    }

    private function enableTwoFactor(AdminUser $admin): array
    {
        $service = app(AdminTwoFactorService::class);
        $secret = $service->generateSecret();
        $recoveryCodes = $service->generateRecoveryCodes();

        $service->enable($admin, $secret, $recoveryCodes);

        return [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    private function encryptedPasswordPayload(string $password): array
    {
        return [
            'password_encrypted' => $this->encryptPassword($password),
            'password_key_id' => app(AdminPasswordCryptoService::class)->publicKeyPayload()['key_id'],
        ];
    }

    private function encryptPassword(string $password): string
    {
        $encrypted = '';
        $ok = openssl_public_encrypt(
            $password,
            $encrypted,
            app(AdminPasswordCryptoService::class)->publicKey(),
            OPENSSL_PKCS1_OAEP_PADDING,
        );

        $this->assertTrue($ok);

        return base64_encode($encrypted);
    }
}
