<?php

namespace App\Http\Requests\Admin\Security;

use App\Models\AdminPermission;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台「编辑角色」请求。
 *
 * 对应管理端 RBAC 角色管理的更新接口（PUT/PATCH）。与新建版本几乎一致，两处关键差异：
 * 1) slug 唯一性用 ignore(当前角色 id) 排除自身，避免「保存时和自己撞名」的误报；
 * 2) is_active 改为 required（编辑时必须显式给出启用状态，不允许缺省沿用）。
 */
class AdminRoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * 编辑角色校验规则。
     *
     * - 先从路由取出正在编辑的角色模型（$role），用于 slug 唯一性排除自身。
     * - name：角色显示名，required。
     * - slug：同新建，regex /^[a-z0-9][a-z0-9_-]*$/ 限定「小写字母/数字开头 + 小写字母数字下划线连字符」，
     *   Rule::unique(...)->ignore($role?->id) 表示唯一性校验时忽略当前角色本身（否则更新时会撞到自己而报重复）。
     * - description：说明，可空。
     * - is_active：启用状态，此处为 required（编辑必须显式指定，区别于新建的 sometimes）。
     * - permission_ids / permission_ids.*：权限点数组，逐项整数且存在于 admin_permissions；
     *   业务白名单再由下方 withValidator 兜底。
     */
    public function rules(): array
    {
        $role = $this->route('adminRole'); // 当前被编辑的角色，用于唯一性忽略自身

        return [
            'name' => ['required', 'string', 'max:80'], // 角色显示名，必填
            'slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9][a-z0-9_-]*$/', // 机器标识字符白名单：小写数字起头
                Rule::unique('admin_roles', 'slug')->ignore($role?->id), // 唯一性排除当前角色自身
            ],
            'description' => ['nullable', 'string', 'max:255'], // 描述，可空
            'is_active' => ['required', 'boolean'], // 编辑时必须显式给出启用状态
            'permission_ids' => ['array'], // 权限点 id 列表
            'permission_ids.*' => ['integer', Rule::exists('admin_permissions', 'id')], // 每个 id 须为整数且库中存在
        ];
    }

    /**
     * 追加校验：确保所选权限点都在「系统白名单」内。
     *
     * 与新建请求同一逻辑：rules() 的 exists 只保证记录存在，这里再用
     * AdminPermissionRegistry::slugs() 系统白名单过滤掉「库里有但代码里已不识别」的权限，
     * 防止把废弃/孤立权限授予角色（纵深防御）。
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // 查询：所选权限里，是否存在 slug 不属于注册表白名单的记录
            $invalid = AdminPermission::query()
                ->whereIn('id', $this->input('permission_ids', []))
                ->whereNotIn('slug', AdminPermissionRegistry::slugs())
                ->exists();

            if ($invalid) {
                // 命中非白名单权限：追加校验错误，整体拒绝保存
                $validator->errors()->add('permission_ids', '权限点不在系统白名单中。');
            }
        });
    }
}
