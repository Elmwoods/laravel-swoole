<?php

namespace Tests\Unit\Admin;

use App\Models\AdminUser;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_accepts_current_window_and_rejects_expired_or_future_codes(): void
    {
        $service = app(AdminTwoFactorService::class);
        $secret = 'JBSWY3DPEHPK3PXP';
        $now = 1_700_000_000;

        $current = $service->totpCode($secret, $now);
        $expired = $service->totpCode($secret, $now - 90);
        $future = $service->totpCode($secret, $now + 90);

        $this->assertTrue($service->verifyTotp($secret, $current, $now));
        $this->assertFalse($service->verifyTotp($secret, $expired, $now));
        $this->assertFalse($service->verifyTotp($secret, $future, $now));
    }

    public function test_secret_and_otpauth_uri_are_authenticator_compatible(): void
    {
        $service = app(AdminTwoFactorService::class);
        $secret = $service->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $uri = $service->otpauthUri('admin@example.com', $secret);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('issuer=Ops%20Center', $uri);
    }

    public function test_recovery_codes_are_hashed_and_single_use(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Two Factor Admin',
            'email' => 'two-factor@example.com',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
        $service = app(AdminTwoFactorService::class);
        $codes = $service->generateRecoveryCodes();

        $service->enable($admin, $service->generateSecret(), $codes);

        $admin->refresh();
        $json = json_encode($admin->two_factor_recovery_codes, JSON_THROW_ON_ERROR);

        $this->assertCount(8, $codes);
        $this->assertStringNotContainsString($codes[0], $json);
        $this->assertTrue($service->consumeRecoveryCode($admin, $codes[0]));
        $this->assertFalse($service->consumeRecoveryCode($admin->refresh(), $codes[0]));
    }

    public function test_profile_security_summary_does_not_expose_secret_or_recovery_hashes(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Summary Admin',
            'email' => 'summary@example.com',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
        $service = app(AdminTwoFactorService::class);
        $secret = $service->generateSecret();
        $service->enable($admin, $secret, $service->generateRecoveryCodes());

        $summary = $service->securitySummary($admin->refresh());
        $json = json_encode($summary, JSON_THROW_ON_ERROR);

        $this->assertTrue($summary['two_factor_enabled']);
        $this->assertArrayNotHasKey('two_factor_secret', $summary);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $summary);
        $this->assertStringNotContainsString($secret, $json);
    }
}
