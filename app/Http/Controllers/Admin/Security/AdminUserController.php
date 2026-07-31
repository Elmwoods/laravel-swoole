<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\AdminPasswordResetRequest;
use App\Http\Requests\Admin\Security\AdminUserStoreRequest;
use App\Http\Requests\Admin\Security\AdminUserUpdateRequest;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\AdminPasswordCryptoService;
use App\Services\Admin\AdminTwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * 后台管理员账号管理控制器。
 *
 * 作用：管理后台管理员账号的增改与安全操作，服务的路由包括：
 *  - GET   admin-users                       分页列出管理员（index）
 *  - POST  admin-users                       新建管理员并分配角色（store）
 *  - PATCH admin-users/{adminUser}           更新管理员信息与角色（update）
 *  - POST  admin-users/{adminUser}/reset-2fa 重置某管理员的 2FA（resetTwoFactor）
 *  - POST  admin-users/{adminUser}/reset-pwd 重置某管理员密码（resetPassword）
 *
 * 「为什么」：为防止「后台失去管理员」，对最后一个超级管理员设有护栏（不可禁用/不可去除超管角色）；
 * 重置 2FA / 重置密码属高危操作，仅超级管理员可执行，且不允许重置自己的 2FA；
 * 重置密码会递增 session_version 以强制该用户全端下线。
 */
class AdminUserController extends Controller
{
    /**
     * 构造函数：注入密码加解密服务与二次验证服务。
     *
     * @param  AdminPasswordCryptoService  $passwordCrypto  密码加解密服务（前端 RSA 加密、后端解密入库）
     * @param  AdminTwoFactorService  $twoFactor  二次验证服务，提供安全摘要与重置能力
     * @return void
     */
    public function __construct(
        private readonly AdminPasswordCryptoService $passwordCrypto,
        private readonly AdminTwoFactorService $twoFactor,
    ) {}

    /**
     * 作用：分页返回管理员列表（含各自角色与安全摘要）。
     *
     * @return JsonResponse items（本页管理员）+ pagination（分页元信息）
     *
     * 「为什么」：per_page 夹在 5~100、page 至少为 1，防止极端分页参数拖垮查询。
     */
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

    /**
     * 作用：新建一个管理员账号并分配角色。
     *
     * @param  AdminUserStoreRequest  $request  已校验的请求（name/email/加密密码载荷/is_active/role_ids）
     * @return JsonResponse 201 状态、data 为序列化后的新管理员
     *
     * 「为什么」：密码由前端 RSA 加密提交，这里 decryptPasswordFromPayload 解密后交由模型
     * 转换器哈希入库，全程不出现明文密码存储。
     */
    public function store(AdminUserStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = AdminUser::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            // 解密前端加密的密码载荷；入库时模型会自动哈希。
            'password' => $this->passwordCrypto->decryptPasswordFromPayload($data),
            'is_active' => $data['is_active'] ?? true,
        ]);
        // 同步用户-角色关联（多对多）。
        $user->roles()->sync($data['role_ids'] ?? []);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->serialize($user->refresh()->load('roles')),
            'timestamp' => now()->timestamp,
        ], 201);
    }

    /**
     * 作用：更新指定管理员的信息与角色，并对「最后一个超级管理员」施加护栏。
     *
     * @param  AdminUserUpdateRequest  $request  已校验的请求（name/email/is_active/role_ids）
     * @param  AdminUser  $adminUser  路由模型绑定注入的目标管理员
     * @return JsonResponse 序列化后的更新结果
     *
     * 「为什么」：当目标是系统中最后一个在职超管时，既不能被禁用、也不能被摘掉超管角色，
     * 否则会导致无人能管理后台（自锁）。护栏仅在 superAdminCount()<=1 时触发。
     */
    public function update(AdminUserUpdateRequest $request, AdminUser $adminUser): JsonResponse
    {
        $data = $request->validated();

        // 目标是最后一个在职超级管理员时，进入护栏检查。
        if ($adminUser->isSuperAdmin() && $this->superAdminCount() <= 1) {
            // 护栏一：不允许禁用最后一个超管。
            if (! $data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => ['不能禁用最后一个超级管理员。'],
                ]);
            }

            // 护栏二：新角色集合必须仍包含「在职的超级管理员角色」，否则视为变相削权。
            if (! $this->roleIdsIncludeActiveSuperAdmin($data['role_ids'])) {
                throw ValidationException::withMessages([
                    'role_ids' => ['不能移除最后一个超级管理员的超级管理员角色。'],
                ]);
            }
        }

        $adminUser->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ])->save();
        // 重新同步用户角色。
        $adminUser->roles()->sync($data['role_ids'] ?? []);

        return $this->success($this->serialize($adminUser->refresh()->load('roles')));
    }

    /**
     * 作用：重置指定管理员的二次验证（清除其 2FA 绑定，令其下次登录需重新设置）。
     *
     * @param  AdminUser  $adminUser  路由模型绑定注入的目标管理员
     * @return JsonResponse 成功返回序列化后的目标管理员；非超管返回 403
     *
     * 「为什么」：这是可越过他人 2FA 的高危操作——仅超级管理员可执行；并显式禁止「重置自己」，
     * 避免超管误清自己的 2FA（应通过正常自助流程处理自己的 2FA）。
     */
    public function resetTwoFactor(AdminUser $adminUser): JsonResponse
    {
        $actor = request()->user('admin');

        // 权限闸门：仅超级管理员可重置他人 2FA。
        if (! $actor?->isSuperAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '只有超级管理员可以重置二次验证。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 403);
        }

        // 禁止对自己执行，防止误操作清掉自身 2FA。
        if ((int) $actor->id === (int) $adminUser->id) {
            throw ValidationException::withMessages([
                'admin_user' => ['不能重置自己的二次验证。'],
            ]);
        }

        // 委托 2FA 服务清除目标用户的二次验证绑定。
        $this->twoFactor->reset($adminUser);

        return $this->success($this->serialize($adminUser->refresh()->load('roles')));
    }

    /**
     * 作用：重置指定管理员的登录密码，并强制其所有会话失效。
     *
     * @param  AdminPasswordResetRequest  $request  已校验的请求（含加密的确认密码载荷）
     * @param  AdminUser  $adminUser  路由模型绑定注入的目标管理员
     * @return JsonResponse 成功返回序列化后的目标管理员；非超管返回 403
     *
     * 「为什么」：仅超级管理员可代改他人密码；改密后递增 session_version，使该用户所有
     * 旧会话（其记录的会话版本已过期）在下一次请求校验时失效，达到强制全端下线的效果。
     */
    public function resetPassword(AdminPasswordResetRequest $request, AdminUser $adminUser): JsonResponse
    {
        // 权限闸门：仅超级管理员可重置他人密码。
        if (! $request->user('admin')?->isSuperAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '只有超级管理员可以重置密码。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 403);
        }

        // 解密前端提交的「已确认」密码载荷（含两次输入一致性校验）得到明文新密码。
        $password = $this->passwordCrypto->decryptConfirmedPasswordFromPayload($request->validated());

        $adminUser->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
            // 会话版本 +1：使目标用户所有旧会话失效，强制其重新登录（全端下线）。
            'session_version' => ((int) $adminUser->session_version) + 1,
        ])->save();

        return $this->success($this->serialize($adminUser->refresh()->load('roles')));
    }

    /**
     * 作用：将管理员模型序列化为对外结构（账号信息、安全摘要、角色列表）。
     *
     * @param  AdminUser  $user  目标管理员
     * @return array 用户字段 + security（2FA 摘要）+ roles 数组
     *
     * 「为什么」：统一 index/store/update/reset* 各接口的用户输出格式；loadMissing 避免重复加载角色。
     */
    private function serialize(AdminUser $user): array
    {
        // 仅在未加载时加载角色关联，避免重复查询。
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
            // 2FA 安全摘要（是否启用、剩余恢复码数量等），不含敏感密钥。
            'security' => $this->twoFactor->securitySummary($user),
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

    /**
     * 作用：统计当前「在职且拥有在职超管角色」的超级管理员数量。
     *
     * @return int 有效超级管理员数量
     *
     * 「为什么」：update() 依据它判断目标是否为「最后一个超管」以决定是否启用护栏；
     * 同时要求用户本身在职（is_active）且其超管角色也在职，避免把已停用的算进来。
     */
    private function superAdminCount(): int
    {
        return AdminUser::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin')->where('is_active', true))
            ->count();
    }

    /**
     * 作用：判断给定角色 ID 集合中是否包含「在职的超级管理员角色」。
     *
     * @param  array  $roleIds  待检查的角色 ID 数组
     * @return bool true 表示集合含在职超管角色，false 表示不含
     *
     * 「为什么」：update() 用它确保对最后一个超管的角色调整后仍保留超管身份，防止变相削权。
     */
    private function roleIdsIncludeActiveSuperAdmin(array $roleIds): bool
    {
        return AdminRole::query()
            ->whereIn('id', $roleIds)
            ->where('slug', 'super_admin')
            ->where('is_active', true)
            ->exists();
    }
}
