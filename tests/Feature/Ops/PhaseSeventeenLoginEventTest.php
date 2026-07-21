<?php

namespace Tests\Feature\Ops;

use App\Models\AdminLoginEvent;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminLoginEventService;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseSeventeenLoginEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_from_an_ip_is_marked_new_and_repeat_is_not(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        $this->loginWithTwoFactor($admin, $secret, '203.0.113.10');

        $first = AdminLoginEvent::query()->where('admin_user_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($first);
        $this->assertTrue($first->is_new_ip);
        $this->assertSame('203.0.113.10', $first->ip_address);

        $this->logoutSession();
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.10');

        $second = AdminLoginEvent::query()->where('admin_user_id', $admin->id)->latest('id')->first();
        $this->assertFalse($second->is_new_ip);

        $this->logoutSession();
        $this->loginWithTwoFactor($admin, $secret, '198.51.100.20');

        $third = AdminLoginEvent::query()->where('admin_user_id', $admin->id)->latest('id')->first();
        $this->assertTrue($third->is_new_ip);
    }

    public function test_login_history_endpoint_returns_only_own_events_newest_first(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $other = $this->createAdmin(['ops.dashboard.view']);

        $service = app(AdminLoginEventService::class);
        AdminLoginEvent::query()->create([
            'admin_user_id' => $other->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'other-agent',
            'trusted' => false,
            'is_new_ip' => true,
            'is_new_user_agent' => true,
        ]);

        $secret = $this->enableTwoFactor($admin);
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.30');
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.31');

        $data = $this->getJson('/api/admin/auth/login-history')
            ->assertOk()
            ->json('data.events');

        $this->assertCount(2, $data);
        $this->assertSame('203.0.113.31', $data[0]['ip_address']);
        $this->assertSame('203.0.113.30', $data[1]['ip_address']);
    }

    private function loginWithTwoFactor(AdminUser $admin, string $secret, string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk();

        // Simulate a later TOTP window so the replay guard (WS1) does not reject
        // repeated logins that happen within the same real 30s step in tests.
        AdminUser::query()->whereKey($admin->id)->update(['two_factor_last_used_step' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/auth/two-factor/challenge', [
                'code' => app(AdminTwoFactorService::class)->totpCode($secret),
            ])
            ->assertOk();
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
