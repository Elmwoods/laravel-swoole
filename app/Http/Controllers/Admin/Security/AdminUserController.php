<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminPasswordResetRequest;
use App\Http\Requests\Admin\Security\AdminUserStoreRequest;
use App\Http\Requests\Admin\Security\AdminUserUpdateRequest;
use App\Models\AdminRole;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = AdminUser::query()
            ->with('roles')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(5, min((int) request('per_page', 20), 100)),
                page: max(1, (int) request('page', 1)),
            );

        return $this->success([
            'items' => collect($users->items())->map(fn (AdminUser $user): array => $this->serialize($user))->all(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(AdminUserStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = AdminUser::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->roles()->sync($data['role_ids'] ?? []);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->serialize($user->refresh()->load('roles')),
            'timestamp' => now()->timestamp,
        ], 201);
    }

    public function update(AdminUserUpdateRequest $request, AdminUser $adminUser): JsonResponse
    {
        $data = $request->validated();

        if (! $data['is_active'] && $adminUser->isSuperAdmin() && $this->superAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'is_active' => ['不能禁用最后一个超级管理员。'],
            ]);
        }

        $adminUser->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ])->save();
        $adminUser->roles()->sync($data['role_ids'] ?? []);

        return $this->success($this->serialize($adminUser->refresh()->load('roles')));
    }

    public function resetPassword(AdminPasswordResetRequest $request, AdminUser $adminUser): JsonResponse
    {
        if (! $request->user('admin')?->isSuperAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '只有超级管理员可以重置密码。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 403);
        }

        $adminUser->forceFill([
            'password' => $request->validated('password'),
            'password_changed_at' => now(),
            'session_version' => ((int) $adminUser->session_version) + 1,
        ])->save();

        return $this->success($this->serialize($adminUser->refresh()->load('roles')));
    }

    private function serialize(AdminUser $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
            'roles' => $user->roles
                ->map(fn (AdminRole $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_active' => $role->is_active,
                ])
                ->values()
                ->all(),
            'created_at' => optional($user->created_at)->toDateTimeString(),
        ];
    }

    private function superAdminCount(): int
    {
        return AdminUser::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin')->where('is_active', true))
            ->count();
    }
}
