<?php

namespace Tests\Unit\Admin;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminCsvExportService;
use App\Services\Admin\AdminAuditPruneService;
use App\Services\Admin\AdminLoginThrottleService;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminSessionSecurityService;
use App\Services\Ops\OpsConfirmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 后台权限判定与审计脱敏单元测试。
 */
class AdminSecurityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_permissions_merge_active_roles_only(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $activeRole = AdminRole::query()->create([
            'name' => 'Active',
            'slug' => 'active',
            'is_active' => true,
        ]);
        $disabledRole = AdminRole::query()->create([
            'name' => 'Disabled',
            'slug' => 'disabled',
            'is_active' => false,
        ]);
        $view = AdminPermission::query()->create([
            'name' => 'Dashboard',
            'slug' => 'ops.dashboard.view',
            'group' => 'ops',
        ]);
        $control = AdminPermission::query()->create([
            'name' => 'Docker',
            'slug' => 'ops.docker.control',
            'group' => 'ops',
        ]);

        $activeRole->permissions()->attach($view->id);
        $disabledRole->permissions()->attach($control->id);
        $admin->roles()->attach([$activeRole->id, $disabledRole->id]);

        $this->assertTrue($admin->hasPermission('ops.dashboard.view'));
        $this->assertFalse($admin->hasPermission('ops.docker.control'));
        $this->assertSame(['ops.dashboard.view'], $admin->permissionSlugs());
    }

    public function test_disabled_admin_has_no_permissions(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $this->assertFalse($admin->hasPermission('ops.dashboard.view'));
        $this->assertSame([], $admin->permissionSlugs());
    }

    public function test_audit_payload_filters_sensitive_values(): void
    {
        $payload = app(AdminAuditService::class)->sanitizePayload([
            'email' => 'admin@example.com',
            'password' => 'secret',
            'token' => 'unsafe-token',
            'nested' => [
                'bot_token' => 'telegram-token',
                'chat_id' => '123456',
            ],
        ]);

        $this->assertSame('admin@example.com', $payload['email']);
        $this->assertSame('[FILTERED]', $payload['password']);
        $this->assertSame('[FILTERED]', $payload['token']);
        $this->assertSame('[FILTERED]', $payload['nested']['bot_token']);
        $this->assertSame('[FILTERED]', $payload['nested']['chat_id']);
    }

    public function test_audit_payload_truncates_long_strings_without_filtering_safe_keys(): void
    {
        $payload = app(AdminAuditService::class)->sanitizePayload([
            'message' => str_repeat('A', 800),
            'nested' => [
                'description' => str_repeat('B', 800),
                'api_key_preview' => 'must-filter',
            ],
        ]);

        $this->assertLessThanOrEqual(503, mb_strlen($payload['message']));
        $this->assertStringEndsWith('...', $payload['message']);
        $this->assertLessThanOrEqual(503, mb_strlen($payload['nested']['description']));
        $this->assertSame('[FILTERED]', $payload['nested']['api_key_preview']);
    }

    public function test_csv_export_escapes_formula_injection_prefixes(): void
    {
        $service = app(AdminCsvExportService::class);

        $this->assertSame("'=SUM(A1:A2)", $service->escapeCell('=SUM(A1:A2)'));
        $this->assertSame("'+payload", $service->escapeCell('+payload'));
        $this->assertSame("'-payload", $service->escapeCell('-payload'));
        $this->assertSame("'@payload", $service->escapeCell('@payload'));
        $this->assertSame('safe payload', $service->escapeCell('safe payload'));
    }

    public function test_ops_confirm_service_accepts_only_fixed_phrase(): void
    {
        $service = app(OpsConfirmService::class);

        $this->assertTrue($service->isConfirmed('CONFIRM'));
        $this->assertFalse($service->isConfirmed('confirm'));
        $this->assertFalse($service->isConfirmed(' CONFIRM '));
        $this->assertFalse($service->isConfirmed(null));
    }

    public function test_password_crypto_decrypts_ciphertext_and_rejects_wrong_key(): void
    {
        $service = app(AdminPasswordCryptoService::class);
        $ciphertext = '';
        $ok = openssl_public_encrypt('secret-password', $ciphertext, $service->publicKey(), OPENSSL_PKCS1_OAEP_PADDING);

        $this->assertTrue($ok);
        $this->assertSame('secret-password', $service->decryptPasswordFromPayload([
            'password_encrypted' => base64_encode($ciphertext),
            'password_key_id' => $service->publicKeyPayload()['key_id'],
        ]));

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->decryptPasswordFromPayload([
            'password_encrypted' => base64_encode($ciphertext),
            'password_key_id' => 'invalid-key-id!!',
        ]);
    }

    public function test_login_throttle_key_normalizes_email_and_includes_ip(): void
    {
        $service = app(AdminLoginThrottleService::class);

        $this->assertSame(
            $service->key('ADMIN@example.com', '127.0.0.1'),
            $service->key('admin@example.com', '127.0.0.1'),
        );
        $this->assertNotSame(
            $service->key('admin@example.com', '127.0.0.1'),
            $service->key('admin@example.com', '127.0.0.2'),
        );
    }

    public function test_session_version_must_match_current_admin_version(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'session_version' => 3,
        ]);

        $this->assertTrue($admin->sessionVersionMatches(3));
        $this->assertFalse($admin->sessionVersionMatches(2));
        $this->assertFalse($admin->sessionVersionMatches(null));
    }

    public function test_admin_session_idle_timeout_handles_missing_boundary_and_expired_activity(): void
    {
        $service = app(AdminSessionSecurityService::class);
        $now = now();

        $this->assertFalse($service->isIdleTimedOut(null, $now));
        $this->assertFalse($service->isIdleTimedOut($now->copy()->subSeconds(AdminSessionSecurityService::IDLE_TIMEOUT_SECONDS)->timestamp, $now));
        $this->assertTrue($service->isIdleTimedOut($now->copy()->subSeconds(AdminSessionSecurityService::IDLE_TIMEOUT_SECONDS + 1)->timestamp, $now));
    }

    public function test_audit_prune_service_validates_retention_days(): void
    {
        $service = app(AdminAuditPruneService::class);

        $this->assertSame(180, $service->normalizeRetentionDays(null));
        $this->assertSame(30, $service->normalizeRetentionDays(30));
        $this->assertSame(3650, $service->normalizeRetentionDays(3650));

        $this->expectException(InvalidArgumentException::class);
        $service->normalizeRetentionDays(29);
    }

    public function test_audit_prune_service_rejects_retention_days_above_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdminAuditPruneService::class)->normalizeRetentionDays(3651);
    }

    public function test_is_super_admin_requires_active_user_and_active_super_role(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $superRole = AdminRole::query()->create([
            'name' => 'Super',
            'slug' => 'super_admin',
            'is_active' => true,
        ]);
        $admin->roles()->attach($superRole->id);

        $this->assertTrue($admin->isSuperAdmin());

        $superRole->forceFill(['is_active' => false])->save();
        $this->assertFalse($admin->refresh()->isSuperAdmin());

        $superRole->forceFill(['is_active' => true])->save();
        $admin->forceFill(['is_active' => false])->save();
        $this->assertFalse($admin->refresh()->isSuperAdmin());
    }
}
