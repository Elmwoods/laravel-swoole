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

/**
 * Phase 17：受信任设备（Trusted Device）功能测试。
 *
 * 场景：管理员开启两步验证（2FA）后，可以在通过一次二次验证时选择“信任本设备”，
 * 系统会下发一枚受信任设备 cookie。之后在该设备上登录可跳过两步验证挑战。
 * 本测试文件覆盖：受信任 cookie 生效跳过 2FA、过期/吊销后仍需 2FA、
 * 仅在勾选 trust_device 时才下发设备、管理员自助列出与吊销设备、
 * 以及重置 2FA 时级联吊销全部受信任设备等关键行为。
 */
class PhaseSeventeenTrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证：携带有效受信任设备 cookie 登录时，直接完成登录并跳过两步验证挑战，
     * 且登录事件被标记为 trusted。
     */
    public function test_valid_trusted_device_cookie_skips_two_factor(): void
    {
        // 建管理员并开启 2FA，再为其签发一枚受信任设备（拿到明文 cookie token）。
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $token = $this->issueDevice($admin);

        // 带上未加密的受信任设备 cookie 登录：应直接返回 admin 数据、无需二次验证。
        $this->withCredentials()
            ->withUnencryptedCookie(AdminTrustedDeviceService::COOKIE_NAME, $token)
            ->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('data.admin.email', $admin->email)
            // 不应出现 requires_two_factor 字段，说明确实跳过了挑战。
            ->assertJsonMissingPath('data.requires_two_factor');

        // 登录后已建立会话，/me 可正常访问。
        $this->getJson('/api/admin/auth/me')->assertOk();

        // 登录事件应记录为受信任登录。
        $this->assertDatabaseHas('admin_login_events', [
            'admin_user_id' => $admin->id,
            'trusted' => true,
        ]);
    }

    /**
     * 验证：受信任设备一旦过期（或被吊销），登录仍需走两步验证挑战，
     * 不再享受跳过待遇。
     */
    public function test_expired_or_revoked_device_still_requires_two_factor(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $token = $this->issueDevice($admin);

        // 把设备的过期时间回拨到昨天，模拟设备已过期。
        AdminTrustedDevice::query()
            ->where('admin_user_id', $admin->id)
            ->update(['expires_at' => now()->subDay()]);

        // 携带已过期 cookie 登录：应返回 requires_two_factor=true 且尚未给出 admin 数据。
        $this->withCredentials()
            ->withUnencryptedCookie(AdminTrustedDeviceService::COOKIE_NAME, $token)
            ->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('secret-password')))
            ->assertOk()
            ->assertJsonPath('data.requires_two_factor', true)
            ->assertJsonMissingPath('data.admin');
    }

    /**
     * 验证：只有在二次验证挑战时显式传入 trust_device=true，才会下发受信任设备；
     * 未勾选时不下发。
     */
    public function test_device_is_only_issued_when_trust_device_is_requested(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        // 挑战时不带 trust_device -> 不应下发设备。
        // Challenge without trust_device -> no device.
        $this->postJson('/api/admin/auth/login', array_merge([
            'email' => $admin->email,
        ], $this->encryptedPasswordPayload('secret-password')))->assertOk();
        $this->postJson('/api/admin/auth/two-factor/challenge', [
            'code' => app(AdminTwoFactorService::class)->totpCode($secret),
        ])->assertOk();

        $this->assertSame(0, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());

        // 重新登录并在挑战时带上 trust_device -> 应下发设备。
        // Fresh login, challenge WITH trust_device -> device issued.
        $this->logoutSession();
        // 清空上次使用的 TOTP step，避免防重放校验拒绝同一验证码复用。
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

    /**
     * 验证：管理员可以列出自己的受信任设备（且响应不泄漏 token_hash），
     * 并能吊销其中一个设备，吊销后该记录从库中删除。
     */
    public function test_admin_can_list_and_revoke_own_trusted_devices(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        $this->issueDevice($admin);
        $device = AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->firstOrFail();

        // 以该管理员身份登录，并写入匹配的会话版本号以通过会话有效性校验。
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_session_version' => (int) $admin->session_version]);

        // 列表返回设备，且不含敏感的 token_hash 字段。
        $this->getJson('/api/admin/auth/trusted-devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.id', $device->id)
            ->assertJsonMissingPath('data.devices.0.token_hash');

        // 吊销该设备。
        $this->postJson("/api/admin/auth/trusted-devices/{$device->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        // 吊销后记录应被删除。
        $this->assertDatabaseMissing('admin_trusted_devices', ['id' => $device->id]);
    }

    /**
     * 验证：重置管理员的两步验证会级联吊销其名下的所有受信任设备。
     */
    public function test_resetting_two_factor_revokes_all_trusted_devices(): void
    {
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $this->enableTwoFactor($admin);
        // 签发两枚设备作为初始状态。
        $this->issueDevice($admin);
        $this->issueDevice($admin);

        $this->assertSame(2, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());

        // 重置 2FA。
        app(AdminTwoFactorService::class)->reset($admin);

        // 所有受信任设备应被清空。
        $this->assertSame(0, AdminTrustedDevice::query()->where('admin_user_id', $admin->id)->count());
    }

    // 辅助：为管理员签发一枚受信任设备，返回明文 cookie token。
    private function issueDevice(AdminUser $admin): string
    {
        return app(AdminTrustedDeviceService::class)
            ->issue($admin, Request::create('http://localhost', 'GET'));
    }

    // 辅助：登出并清空会话，用于模拟一次全新的登录流程。
    private function logoutSession(): void
    {
        auth('admin')->logout();
        session()->invalidate();
        session()->regenerateToken();
    }

    // 辅助：创建一个启用的管理员，并绑定一个带指定权限的测试角色。
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

    // 辅助：为管理员开启两步验证并返回 TOTP 密钥（供生成验证码用）。
    private function enableTwoFactor(AdminUser $admin): string
    {
        $service = app(AdminTwoFactorService::class);
        $secret = $service->generateSecret();
        $service->enable($admin, $secret, $service->generateRecoveryCodes());

        return $secret;
    }

    // 辅助：构造登录接口所需的 RSA 加密密码载荷（前端不传明文密码）。
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
