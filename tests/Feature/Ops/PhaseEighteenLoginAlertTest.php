<?php

namespace Tests\Feature\Ops;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseEighteenLoginAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 与本机 .env 通道配置解耦：默认关闭所有通道，用例按需只开自己那一个。
        foreach ((array) config('ops.alerts.channels') as $channel) {
            config()->set("ops.alerts.{$channel}.enabled", false);
        }

        Http::preventStrayRequests();
    }

    public function test_new_ip_login_raises_and_pushes_security_alert(): void
    {
        $this->enableWebhook();
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        // 首登（基线）→ 无告警。
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.10');
        $this->assertSame(0, OpsAlert::query()->where('source', 'security_login')->count());
        $this->logoutSession();

        // 新 IP 登录 → 建告警 + 推送。
        $this->loginWithTwoFactor($admin, $secret, '198.51.100.20');

        $alert = OpsAlert::query()->where('source', 'security_login')->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString($admin->email, (string) $alert->message);
        $this->assertStringContainsString('新 IP', (string) $alert->message);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.com'));
    }

    public function test_first_ever_login_does_not_raise_alert(): void
    {
        $this->enableWebhook();
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        $this->loginWithTwoFactor($admin, $secret, '203.0.113.30');

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_login')->count());
        Http::assertNothingSent();
    }

    public function test_same_ip_repeat_login_does_not_raise_alert(): void
    {
        $this->enableWebhook();
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        $this->loginWithTwoFactor($admin, $secret, '203.0.113.40');
        $this->logoutSession();
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.40');

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_login')->count());
        Http::assertNothingSent();
    }

    public function test_new_user_agent_from_known_ip_raises_alert(): void
    {
        $this->enableWebhook();
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        // 基线：IP A + UA1。
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.50', 'Mozilla/5.0 AgentOne');
        $this->logoutSession();

        // 同 IP、新 UA → is_new_ip=false, is_new_user_agent=true → 告警。
        $this->loginWithTwoFactor($admin, $secret, '203.0.113.50', 'Mozilla/5.0 AgentTwo');

        $alert = OpsAlert::query()->where('source', 'security_login')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('新设备', (string) $alert->message);
    }

    public function test_disabled_config_suppresses_alert(): void
    {
        config()->set('ops.alerts.login_alerts.enabled', false);
        $this->enableWebhook();
        $admin = $this->createAdmin(['ops.dashboard.view']);
        $secret = $this->enableTwoFactor($admin);

        $this->loginWithTwoFactor($admin, $secret, '203.0.113.60');
        $this->logoutSession();
        $this->loginWithTwoFactor($admin, $secret, '198.51.100.70');

        $this->assertSame(0, OpsAlert::query()->where('source', 'security_login')->count());
        Http::assertNothingSent();
    }

    private function enableWebhook(): void
    {
        config()->set('ops.alerts.webhook.enabled', true);
        config()->set('ops.alerts.webhook.url', 'https://hooks.example.com/ops');
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    }

    private function loginWithTwoFactor(AdminUser $admin, string $secret, string $ip, ?string $userAgent = null): void
    {
        $headers = $userAgent !== null ? ['User-Agent' => $userAgent] : [];

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/auth/login', array_merge([
                'email' => $admin->email,
            ], $this->encryptedPasswordPayload('secret-password')), $headers)
            ->assertOk();

        // 绕过 WS1 重放保护：模拟后续时间步。
        AdminUser::query()->whereKey($admin->id)->update(['two_factor_last_used_step' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/auth/two-factor/challenge', [
                'code' => app(AdminTwoFactorService::class)->totpCode($secret),
            ], $headers)
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
