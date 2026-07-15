<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminRoleStoreRequest;
use App\Http\Requests\Admin\Security\AdminRoleUpdateRequest;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminRoleController extends Controller
{
    public function __construct(
        private readonly AdminPermissionRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        $this->registry->syncDefaults();

        return $this->success([
            'roles' => AdminRole::query()
                ->with('permissions')
                ->orderBy('id')
                ->get()
                ->map(fn (AdminRole $role): array => $this->serialize($role))
                ->all(),
            'permissions' => AdminPermission::query()
                ->whereIn('slug', AdminPermissionRegistry::slugs())
                ->orderBy('group')
                ->orderBy('slug')
                ->get()
                ->map(fn (AdminPermission $permission): array => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'group' => $permission->group,
                    'description' => $permission->description,
                ])
                ->all(),
        ]);
    }

    public function store(AdminRoleStoreRequest $request): JsonResponse
    {
        $this->registry->syncDefaults();
        $data = $request->validated();
        $role = AdminRole::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_system' => false,
        ]);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->serialize($role->refresh()->load('permissions')),
            'timestamp' => now()->timestamp,
        ], 201);
    }

    public function update(AdminRoleUpdateRequest $request, AdminRole $adminRole): JsonResponse
    {
        $this->registry->syncDefaults();
        $data = $request->validated();

        if ($adminRole->slug === 'super_admin' && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => ['不能禁用超级管理员角色。'],
            ]);
        }

        if ($adminRole->slug === 'super_admin' && ! $this->permissionIdsIncludeAllSystemPermissions($data['permission_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'permission_ids' => ['超级管理员角色必须保留全部系统权限。'],
            ]);
        }

        $adminRole->forceFill([
            'name' => $data['name'],
            'slug' => $adminRole->is_system ? $adminRole->slug : $data['slug'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'],
        ])->save();
        $adminRole->permissions()->sync($data['permission_ids'] ?? []);

        return $this->success($this->serialize($adminRole->refresh()->load('permissions')));
    }

    private function serialize(AdminRole $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'is_system' => $role->is_system,
            'permissions' => $role->permissions
                ->map(fn (AdminPermission $permission): array => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'group' => $permission->group,
                    'description' => $permission->description,
                ])
                ->sortBy('slug')
                ->values()
                ->all(),
        ];
    }

    private function permissionIdsIncludeAllSystemPermissions(array $permissionIds): bool
    {
        $selectedSlugs = AdminPermission::query()
            ->whereIn('id', $permissionIds)
            ->whereIn('slug', AdminPermissionRegistry::slugs())
            ->pluck('slug')
            ->all();

        return empty(array_diff(AdminPermissionRegistry::slugs(), $selectedSlugs));
    }
}
