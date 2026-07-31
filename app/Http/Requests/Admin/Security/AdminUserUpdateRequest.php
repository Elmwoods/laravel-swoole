<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台「编辑管理员账号」请求。
 *
 * 对应管理端用户管理的更新接口（PUT/PATCH）。与新建版本相比：
 * 1) 不含密码字段 —— 改密走独立的 AdminPasswordResetRequest 流程，避免和资料编辑混在一起；
 * 2) email 唯一性用 ignore(当前用户 id) 排除自身；
 * 3) is_active 改为 required（编辑必须显式给出启用状态）。
 */
class AdminUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * 编辑管理员校验规则。
     *
     * - 先从路由取出正在编辑的用户模型（$adminUser），用于 email 唯一性排除自身。
     * - name：姓名，required。
     * - email：登录邮箱，email 格式 + Rule::unique(...)->ignore($adminUser?->id) 唯一但忽略当前用户本身
     *   （否则保存原邮箱会撞到自己而误报重复）。
     * - is_active：启用状态，required（编辑必须显式指定，区别于新建的 sometimes）。
     * - role_ids：required + array + min:1，至少保留一个角色，禁止把账号改成空权限。
     * - role_ids.*：每个角色 id 须为整数，且 Rule::exists(...)->where('is_active', true) 要求角色
     *   存在且处于启用状态，防止绑定已停用角色。
     */
    public function rules(): array
    {
        $adminUser = $this->route('adminUser'); // 当前被编辑的用户，用于邮箱唯一性忽略自身

        return [
            'name' => ['required', 'string', 'max:80'], // 姓名，必填
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('admin_users', 'email')->ignore($adminUser?->id), // 唯一性排除当前用户自身
            ],
            'is_active' => ['required', 'boolean'], // 编辑时必须显式给出启用状态
            'role_ids' => ['required', 'array', 'min:1'], // 至少保留一个角色，禁止改成空权限
            'role_ids.*' => [
                'integer',
                Rule::exists('admin_roles', 'id')->where('is_active', true), // 角色须存在且处于启用状态
            ],
        ];
    }
}
