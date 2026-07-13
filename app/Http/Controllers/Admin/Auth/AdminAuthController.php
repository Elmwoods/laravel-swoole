<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Models\AdminUser;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly AdminPermissionRegistry $permissions,
    ) {}

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $this->permissions->syncDefaults();
        $admin = AdminUser::query()->where('email', $request->validated('email'))->first();

        if (! $admin || ! Hash::check($request->validated('password'), $admin->password)) {
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

        auth('admin')->login($admin);
        $request->session()->regenerate();

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->record($request, 'admin.auth', 'login', 'success', 200, admin: $admin);

        return $this->success($this->profile($admin));
    }

    public function me(): JsonResponse
    {
        return $this->success($this->profile(request()->user('admin')));
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
        ];
    }
}
