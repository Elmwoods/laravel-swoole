<?php

namespace Tests\Feature\Ops;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminSessionRegistryService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseTwentyActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_registers_an_active_session(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret);

        $this->assertSame(1, AdminSession::query()->where('admin_user_id', $admin->id)->whereNull('revoked_at')->count());
        $this->getJson('/api/admin/auth/me')->assertOk();
    }

    public function test_revoked_session_is_forced_out_on_next_request(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret);

        $this->getJson('/api/admin/auth/me')->assertOk();

        AdminSession::query()->where('admin_user_id', $admin->id)->update(['revoked_at' => now()]);

        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', '会话已被注销，请重新登录后台。');
    }

    public function test_missing_session_row_is_lazily_reregistered_not_kicked(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret);

        AdminSession::query()->where('admin_user_id', $admin->id)->delete();

        $this->getJson('/api/admin/auth/me')->assertOk();
        $this->assertSame(1, AdminSession::query()->where('admin_user_id', $admin->id)->count());
    }

    public function test_revoke_is_scoped_to_the_owning_admin(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $other = $this->createAdmin(['ops.dashboard.view']);

        $otherSession = AdminSession::query()->create([
            'admin_user_id' => $other->id,
            'session_token_hash' => hash('sha256', 'other-session'),
            'label' => 'Chrome · macOS',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'ua',
            'last_activity_at' => now(),
        ]);

        $service = app(AdminSessionRegistryService::class);

        $this->assertFalse($service->revoke($admin, $otherSession->id));
        $this->assertNull($otherSession->refresh()->revoked_at);

        $this->assertTrue($service->revoke($other, $otherSession->id));
        $this->assertNotNull($otherSession->refresh()->revoked_at);
    }

    public function test_revoke_others_keeps_current_session(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret);

        // 造两条“其他设备”会话。
        foreach (['device-a', 'device-b'] as $seed) {
            AdminSession::query()->create([
                'admin_user_id' => $admin->id,
                'session_token_hash' => hash('sha256', $seed),
                'label' => 'Firefox · Linux',
                'ip_address' => '10.0.0.2',
                'user_agent' => 'ua',
                'last_activity_at' => now(),
            ]);
        }

        $this->assertSame(3, AdminSession::query()->where('admin_user_id', $admin->id)->whereNull('revoked_at')->count());

        $this->postJson('/api/admin/auth/sessions/revoke-others')
            ->assertOk()
            ->assertJsonPath('data.revoked', 2);

        // 仅当前会话仍然活跃。
        $this->assertSame(1, AdminSession::query()->where('admin_user_id', $admin->id)->whereNull('revoked_at')->count());
        $this->getJson('/api/admin/auth/me')->assertOk();
    }

    public function test_sessions_endpoint_lists_only_own_sessions_with_current_flag(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $other = $this->createAdmin(['ops.dashboard.view']);
        AdminSession::query()->create([
            'admin_user_id' => $other->id,
            'session_token_hash' => hash('sha256', 'foreign'),
            'label' => 'Chrome · Windows',
            'ip_address' => '10.0.0.9',
            'user_agent' => 'ua',
            'last_activity_at' => now(),
        ]);

        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret);

        $data = $this->getJson('/api/admin/auth/sessions')
            ->assertOk()
            ->json('data.sessions');

        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['current']);
        $this->assertArrayNotHasKey('session_token_hash', $data[0]);
    }

    public function test_revoke_specific_session_endpoint(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret);

        $remote = AdminSession::query()->create([
            'admin_user_id' => $admin->id,
            'session_token_hash' => hash('sha256', 'remote'),
            'label' => 'Safari · iOS',
            'ip_address' => '10.0.0.3',
            'user_agent' => 'ua',
            'last_activity_at' => now(),
        ]);

        $this->postJson("/api/admin/auth/sessions/{$remote->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->assertNotNull($remote->refresh()->revoked_at);
        $this->getJson('/api/admin/auth/me')->assertOk();
    }

    private function loginWithTwoFactor(AdminUser $admin, string $secret): void
    {
        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();

        AdminUser::query()->whereKey($admin->id)->update(['two_factor_last_used_step' => null]);

        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'code' => app(AdminTwoFactorService::class)->totpCode($secret),
        ])->assertOk();
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'Ops Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

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

    private function enableTwoFactor(AdminUser $admin): string
    {
        $service = app(AdminTwoFactorService::class);
        $secret = $service->generateSecret();
        $service->enable($admin, $secret, $service->generateRecoveryCodes());

        return $secret;
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
