<?php

namespace App\Http\Requests\Admin\Security;

use App\Models\AdminPermission;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台「新建角色」请求。
 *
 * 对应管理端 RBAC 角色管理的创建接口（POST）：录入角色名、唯一 slug、描述、
 * 启用状态，并绑定一组权限点。角色决定管理员能做什么，属于权限体系的核心写入口。
 */
class AdminRoleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * 新建角色校验规则。
     *
     * - name：角色显示名，required。
     * - slug：角色机器标识，代码/配置里据此引用角色，要求最严格：
     *   regex /^[a-z0-9][a-z0-9_-]*$/ 表示「必须以小写字母或数字开头，其后仅允许小写字母、数字、
     *   下划线、连字符」——统一命名规范、便于用在 URL/配置键，并杜绝空格及特殊字符；
     *   unique:admin_roles,slug 保证全局唯一，避免两个角色标识撞车导致引用歧义。
     * - description：说明文字，可空。
     * - is_active：是否启用，sometimes 表示不传则不改（新建时可缺省走默认值）。
     * - permission_ids：权限点 id 数组；permission_ids.* 逐项要求为整数且存在于 admin_permissions 表，
     *   防止绑定不存在的权限。注意 exists 只保证「库里有这条记录」，还不够 ——
     *   业务白名单校验在下方 withValidator 里补充。
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'], // 角色显示名，必填
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:admin_roles,slug'], // 机器标识：小写数字起头、字符白名单、全局唯一
            'description' => ['nullable', 'string', 'max:255'], // 描述，可空
            'is_active' => ['sometimes', 'boolean'], // 启用状态，未提交则不改
            'permission_ids' => ['array'], // 权限点 id 列表
            'permission_ids.*' => ['integer', Rule::exists('admin_permissions', 'id')], // 每个 id 须为整数且库中存在
        ];
    }

    /**
     * 追加校验：确保所选权限点都在「系统白名单」内。
     *
     * rules() 里的 exists 只能保证权限记录存在于数据库；但代码里真正被识别、可执行的权限点
     * 由 AdminPermissionRegistry::slugs() 定义（系统白名单）。这里再查一遍：若所选 id 中存在
     * slug 不在白名单里的（例如残留的历史/孤立权限记录），则判定非法并报错，
     * 防止把无效或已废弃的权限授予角色，属于纵深防御。
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
