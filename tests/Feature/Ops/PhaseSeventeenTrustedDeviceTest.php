<?php

namespace Tests\Feature\Ops;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminTrustedDevice;
use App\Models\AdminUser;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminTrustedDeviceService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseSeventeenTrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_trusted_device_cookie_skips_two_factor(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $token = $this->issueDevice($admin);

        $this->withCredentials()
            ->withUnencryptedCookie(AdminTrustedDeviceService::COOKIE_NAME, $token)
            ->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('data.admin.email', $admin->email)
            ->assertJsonMissingPath('data.requires_two_factor');

        $this->getJson('/api/admin/auth/me')->assertOk();

        $this->assertDatabaseHas('admin_login_events', [
            'admin_user_id' => $admin->id,
            'trusted' => true,
        ]);
    }

    public function test_expired_or_revoked_device_still_requires_two_factor(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $token = $this->issueDevice($admin);

        AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->withCredentials()
            ->withUnencryptedCookie(AdminTrustedDeviceService::COOKIE_NAME, $token)
            ->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('data.requires_two_factor', true)
            ->assertJsonMissingPath('data.admin');
    }

    public function test_device_is_only_issued_when_trust_device_is_requested(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        // Challenge without trust_device -> no device.
        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();
        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'code' => app(AdminTwoFactorService::class)->totpCode($secret),
        ])->assertOk();

        $this->assertSame(0, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());

        // Fresh login, challenge WITH trust_device -> device issued.
        $this->logoutSession();
        AdminUser::query()->whereKey($admin->id)->update(['two_factor_last_used_step' => null]);

        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();
        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'code' => app(AdminTwoFactorService::class)->totpCode($secret),
            'trust_device' => true,
        ])->assertOk();

        $this->assertSame(1, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());
    }

    public function test_admin_can_list_and_revoke_own_trusted_devices(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $this->issueDevice($admin);
        $device = AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_session_version' => (int) $admin->session_version]);

        $this->getJson('/api/admin/auth/trusted-devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.id', $device->id)
            ->assertJsonMissingPath('data.devices.0.token_hash');

        $this->postJson("/api/admin/auth/trusted-devices/{$device->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->assertDatabaseMissing('admin_trusted_devices', ['id' => $device->id]);
    }

    public function test_resetting_two_factor_revokes_all_trusted_devices(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $this->issueDevice($admin);
        $this->issueDevice($admin);

        $this->assertSame(2, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());

        app(AdminTwoFactorService::class)->reset($admin);

        $this->assertSame(0, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());
    }

    private function issueDevice(AdminUser $admin): string
    {
        return app(AdminTrustedDeviceService::class)
            ->issue($admin, Request::create('http://localhost', 'GET'));
    }

    private function logoutSession(): void
    {
        auth('admin')->logout();
        session()->invalidate();
        session()->regenerateToken();
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
