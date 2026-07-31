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

/**
 * 后台角色（RBAC）管理控制器。
 *
 * 作用：管理后台的角色及其权限分配（RBAC 中的「角色-权限」关系），服务的路由包括：
 *  - GET   admin-roles          列出全部角色及可分配权限清单（index）
 *  - POST  admin-roles          新建自定义角色并分配权限（store）
 *  - PATCH admin-roles/{role}   更新角色信息与权限（update）
 *
 * 「为什么」：每个写入前都先 syncDefaults() 保证系统默认权限/角色齐备；对 super_admin
 * 超级管理员角色有特殊护栏（不可禁用、必须保留全部系统权限、slug 不可改），防止提权/自锁。
 */
class AdminRoleController extends Controller
{
    /**
     * 构造函数：注入权限注册表服务。
     *
     * @param  AdminPermissionRegistry  $registry  权限注册表，负责同步系统默认权限/角色与提供权限 slug 全集
     * @return void
     */
    public function __construct(
        private readonly AdminPermissionRegistry $registry,
    ) {}

    /**
     * 作用：返回全部角色（含各自权限）以及系统可分配的权限清单。
     *
     * @return JsonResponse roles（角色数组）+ permissions（可分配权限数组）
     *
     * 「为什么」：permissions 只取注册表登记的系统 slug（whereIn slugs），过滤历史遗留权限；
     * 先 syncDefaults 确保默认角色/权限已就位，避免新环境返回空集。
     */
    public function index(): JsonResponse
    {
        // 同步系统默认权限与角色，保证下面查询能拿到完整基线数据。
        $this->registry->syncDefaults();

        return $this->success([
            'roles' => AdminRole::query()
                ->with('permissions')
                ->orderBy('id')
                ->get()
                ->map(fn (AdminRole $role): array => $this->serialize($role))
                ->all(),
            // 仅取注册表登记的系统权限 slug，过滤掉历史遗留或未注册的权限。
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

    /**
     * 作用：新建一个自定义角色并分配权限。
     *
     * @param  AdminRoleStoreRequest  $request  已校验的请求（name/slug/description/is_active/permission_ids）
     * @return JsonResponse 201 状态、data 为序列化后的新角色
     *
     * 「为什么」：新建角色强制 is_system=false（仅系统内置角色为 true），
     * 保证用户无法通过接口伪造「系统角色」而绕过后续护栏。
     */
    public function store(AdminRoleStoreRequest $request): JsonResponse
    {
        // 同步默认权限，确保随后 sync 的权限 ID 都是有效的已注册权限。
        $this->registry->syncDefaults();
        $data = $request->validated();
        $role = AdminRole::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            // 强制非系统角色，防止伪造系统角色规避护栏。
            'is_system' => false,
        ]);
        // 同步角色-权限关联（多对多），未传则清空为无权限。
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->serialize($role->refresh()->load('permissions')),
            'timestamp' => now()->timestamp,
        ], 201);
    }

    /**
     * 作用：更新指定角色的信息与权限分配，并对超级管理员角色施加护栏。
     *
     * @param  AdminRoleUpdateRequest  $request  已校验的请求（name/slug/description/is_active/permission_ids）
     * @param  AdminRole  $adminRole  路由模型绑定注入的目标角色
     * @return JsonResponse 序列化后的更新结果
     *
     * 「为什么」：两道护栏专门保护 super_admin——不允许禁用、且必须保留系统全部权限，
     * 否则一旦被削权/禁用将导致无人可管理后台（提权/自锁风险）。系统角色的 slug 不可修改。
     */
    public function update(AdminRoleUpdateRequest $request, AdminRole $adminRole): JsonResponse
    {
        $this->registry->syncDefaults();
        $data = $request->validated();

        // 护栏一：禁止禁用超级管理员角色。
        if ($adminRole->slug === 'super_admin' && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => ['不能禁用超级管理员角色。'],
            ]);
        }

        // 护栏二：超级管理员角色必须保留系统全部权限，防止被削权。
        if ($adminRole->slug === 'super_admin' && ! $this->permissionIdsIncludeAllSystemPermissions($data['permission_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'permission_ids' => ['超级管理员角色必须保留全部系统权限。'],
            ]);
        }

        $adminRole->forceFill([
            'name' => $data['name'],
            // 系统角色的 slug 锁定不可改，仅自定义角色允许改 slug。
            'slug' => $adminRole->is_system ? $adminRole->slug : $data['slug'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'],
        ])->save();
        // 重新同步该角色的权限集合。
        $adminRole->permissions()->sync($data['permission_ids'] ?? []);

        return $this->success($this->serialize($adminRole->refresh()->load('permissions')));
    }

    /**
     * 作用：将角色模型序列化为对外结构（含其权限列表，按 slug 排序）。
     *
     * @param  AdminRole  $role  目标角色
     * @return array 角色字段 + permissions 数组
     *
     * 「为什么」：统一 index/store/update 的角色输出格式；loadMissing 避免重复加载关联。
     */
    private function serialize(AdminRole $role): array
    {
        // 仅在未加载时才加载权限关联，避免重复查询。
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

    /**
     * 作用：判断给定的权限 ID 集合是否覆盖了系统全部权限 slug。
     *
     * @param  array  $permissionIds  待检查的权限 ID 数组
     * @return bool true 表示已包含系统全部权限，false 表示有缺失
     *
     * 「为什么」：update() 用它来保证 super_admin 不被削权——先把选中 ID 映射回其
     * 系统 slug，再用 array_diff 检查系统全集是否被完全覆盖（差集为空即全覆盖）。
     */
    private function permissionIdsIncludeAllSystemPermissions(array $permissionIds): bool
    {
        // 将选中的权限 ID 映射为其对应的系统权限 slug（仅统计注册表内的 slug）。
        $selectedSlugs = AdminPermission::query()
            ->whereIn('id', $permissionIds)
            ->whereIn('slug', AdminPermissionRegistry::slugs())
            ->pluck('slug')
            ->all();

        // 系统全集与已选集合求差：无差集即代表全部系统权限均已包含。
        return empty(array_diff(AdminPermissionRegistry::slugs(), $selectedSlugs));
    }
}
