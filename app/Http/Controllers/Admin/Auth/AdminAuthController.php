<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminLoginEventService;
use App\Services\Admin\AdminLoginThrottleService;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminPermissionRegistry;
use App\Services\Admin\AdminSessionSecurityService;
use App\Services\Admin\AdminTrustedDeviceService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly AdminLoginThrottleService $throttle,
        private readonly AdminPasswordCryptoService $passwordCrypto,
        private readonly AdminPermissionRegistry $permissions,
        private readonly AdminSessionSecurityService $sessions,
        private readonly AdminTwoFactorService $twoFactor,
        private readonly AdminLoginEventService $loginEvents,
        private readonly AdminTrustedDeviceService $trustedDevices,
    ) {}

    public function passwordKey(): JsonResponse
    {
        return $this->success($this->passwordCrypto->publicKeyPayload());
    }

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $this->permissions->syncDefaults();
        $email = $request->validated('email');
        $ip = (string) $request->ip();

        if ($this->throttle->tooManyAttempts($email, $ip)) {
            $waitSeconds = max(1, $this->throttle->availableIn($email, $ip));
            $this->audit->record($request, 'admin.auth', 'login_locked', 'failure', 429, message: 'too_many_attempts');

            return response()->json([
                'code' => 429,
                'message' => "登录失败次数过多，请 {$waitSeconds} 秒后再试。",
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 429);
        }

        $password = $this->passwordCrypto->decryptPasswordFromPayload($request->validated());
        $admin = AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if (! $admin || ! Hash::check($password, $admin->password)) {
            $this->throttle->hit($email, $ip);
            $this->audit->record($request, 'admin.auth', 'login', 'failure', 422, message: 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => ['登录邮箱或密码不正确。'],
            ]);
        }

        if (! $admin->is_active) {
            $this->audit->record($request, 'admin.auth', 'login', 'failure', 422, admin: $admin, message: 'disabled');

            return response()->json([
                'message' => '后台账号已被禁用。',
                'errors' => ['email' => ['后台账号已被禁用。']],
            ], 422);
        }

        $this->throttle->clear($email, $ip);

        if ($admin->twoFactorEnabled()) {
            $device = $this->trustedDevices->findValid(
                $admin,
                $request->cookie(AdminTrustedDeviceService::COOKIE_NAME),
            );

            if ($device !== null) {
                $this->trustedDevices->touch($device, $request);
                $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin, payload: [
                    'trusted_device' => true,
                ]);

                return $this->success($this->completeLogin($request, $admin, true));
            }
        }

        $this->putPendingTwoFactorSession($request, $admin);

        if (! $admin->twoFactorEnabled()) {
            $secret = $this->twoFactor->generateSecret();
            $request->session()->put('admin_two_factor_pending_secret', $secret);
            $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin, payload: [
                'requires_two_factor_setup' => true,
            ]);

            return $this->success([
                'requires_two_factor_setup' => true,
                'setup' => [
                    'secret' => $secret,
                    'otpauth_uri' => $this->twoFactor->otpauthUri($admin->email, $secret),
                ],
            ]);
        }

        $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin, payload: [
            'requires_two_factor' => true,
        ]);

        return $this->success([
            'requires_two_factor' => true,
        ]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $admin = $this->pendingTwoFactorAdmin($request);
        $secret = (string) $request->session()->get('admin_two_factor_pending_secret', '');

        if (! $admin || $secret === '') {
            return $this->twoFactorExpiredResponse();
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'trust_device' => ['nullable', 'boolean'],
        ]);

        $usedStep = $this->twoFactor->matchStep($secret, $data['code']);

        if ($usedStep === null) {
            $this->audit->record($request, 'admin.auth', 'two_factor_setup', 'failure', 422, admin: $admin);

            throw ValidationException::withMessages([
                'code' => ['二次验证码不正确。'],
            ]);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $this->twoFactor->enable($admin, $secret, $recoveryCodes, $usedStep);
        $this->audit->record($request, 'admin.auth', 'two_factor_setup', 'success', 200, admin: $admin);
        $this->maybeIssueTrustedDevice($request, $admin->refresh());

        return $this->success([
            'profile' => $this->completeLogin($request, $admin->refresh()),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function challengeTwoFactor(Request $request): JsonResponse
    {
        $admin = $this->pendingTwoFactorAdmin($request);

        if (! $admin || ! $admin->twoFactorEnabled()) {
            return $this->twoFactorExpiredResponse();
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32', 'required_without:code'],
            'trust_device' => ['nullable', 'boolean'],
        ]);

        $verified = isset($data['code']) && $data['code'] !== ''
            ? $this->twoFactor->verifyLoginTotp($admin, $data['code'])
            : $this->twoFactor->consumeRecoveryCode($admin, (string) ($data['recovery_code'] ?? ''));

        if (! $verified) {
            $this->audit->record($request, 'admin.auth', 'two_factor_challenge', 'failure', 422, admin: $admin);

            $field = isset($data['recovery_code']) ? 'recovery_code' : 'code';

            throw ValidationException::withMessages([
                $field => ['二次验证码或恢复码不正确。'],
            ]);
        }

        $this->audit->record($request, 'admin.auth', 'two_factor_challenge', 'success', 200, admin: $admin);
        $this->maybeIssueTrustedDevice($request, $admin);

        return $this->success($this->completeLogin($request, $admin->refresh()));
    }

    public function me(): JsonResponse
    {
        return $this->success($this->profile(request()->user('admin')));
    }

    public function loginHistory(Request $request): JsonResponse
    {
        return $this->success([
            'events' => $this->loginEvents->history($request->user('admin'), 20),
        ]);
    }

    public function trustedDevices(Request $request): JsonResponse
    {
        return $this->success([
            'devices' => $this->trustedDevices->list($request->user('admin')),
        ]);
    }

    public function revokeTrustedDevice(Request $request, int $device): JsonResponse
    {
        $revoked = $this->trustedDevices->revoke($request->user('admin'), $device);

        $this->audit->record($request, 'admin.auth', 'trusted_device_revoke', $revoked ? 'success' : 'failure', $revoked ? 200 : 404, admin: $request->user('admin'), payload: [
            'device_id' => $device,
        ]);

        return $this->success([
            'revoked' => $revoked,
        ]);
    }

    public function logout(): JsonResponse
    {
        $request = request();
        $admin = $request->user('admin');

        $this->audit->record($request, 'admin.auth', 'logout', 'success', 200, admin: $admin);
        auth('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(['logged_out' => true]);
    }

    private function profile(AdminUser $admin): array
    {
        $admin->load('roles.permissions');

        return [
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => $admin->is_active,
                'last_login_at' => optional($admin->last_login_at)->toDateTimeString(),
            ],
            'roles' => $admin->roles
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_active' => $role->is_active,
                ])
                ->values()
                ->all(),
            'permissions' => $admin->permissionSlugs(),
            'security' => [
                'last_login_at' => optional($admin->last_login_at)->toDateTimeString(),
                'last_login_ip' => $admin->last_login_ip,
                'last_login_user_agent' => $admin->last_login_user_agent,
                'current_ip' => request()->ip(),
                'current_user_agent' => $this->userAgentSummary(request()->userAgent()),
                'session_version' => (int) $admin->session_version,
                ...$this->twoFactor->securitySummary($admin),
            ],
        ];
    }

    private function putPendingTwoFactorSession(Request $request, AdminUser $admin): void
    {
        $request->session()->put('admin_two_factor_pending_admin_id', $admin->id);
        $request->session()->put('admin_two_factor_pending_at', now()->timestamp);
        $request->session()->forget('admin_two_factor_pending_secret');
    }

    private function maybeIssueTrustedDevice(Request $request, AdminUser $admin): void
    {
        if (! $request->boolean('trust_device')) {
            return;
        }

        $token = $this->trustedDevices->issue($admin, $request);

        Cookie::queue(Cookie::make(
            AdminTrustedDeviceService::COOKIE_NAME,
            $token,
            AdminTrustedDeviceService::LIFETIME_DAYS * 24 * 60,
            '/',
            null,
            app()->environment('production'),
            true,
            false,
            'lax',
        ));
    }

    private function pendingTwoFactorAdmin(Request $request): ?AdminUser
    {
        $id = $request->session()->get('admin_two_factor_pending_admin_id');

        if (! $id) {
            return null;
        }

        return AdminUser::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    private function completeLogin(Request $request, AdminUser $admin, bool $trusted = false): array
    {
        auth('admin')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget([
            'admin_two_factor_pending_admin_id',
            'admin_two_factor_pending_secret',
            'admin_two_factor_pending_at',
        ]);
        $request->session()->put('admin_session_version', (int) $admin->session_version);
        $this->sessions->touch($request);

        $this->loginEvents->record($admin, $request, $trusted);

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_login_user_agent' => $this->userAgentSummary($request->userAgent()),
        ])->save();

        return $this->profile($admin->refresh());
    }

    private function twoFactorExpiredResponse(): JsonResponse
    {
        return response()->json([
            'code' => 401,
            'message' => '二次验证会话已失效，请重新登录。',
            'data' => null,
            'timestamp' => now()->timestamp,
        ], 401);
    }

    private function userAgentSummary(?string $userAgent): string
    {
        $summary = (string) $userAgent;
        $summary = preg_replace('/(token|password|authorization|cookie)=([^;\s]+)/i', '$1=[FILTERED]', $summary) ?? $summary;
        $summary = preg_replace('/[\r\n\t]+/', ' ', $summary) ?? $summary;

        return Str::limit($summary, 180, '');
    }
}
