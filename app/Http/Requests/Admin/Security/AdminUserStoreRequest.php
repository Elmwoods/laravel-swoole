<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台「新建管理员账号」请求。
 *
 * 对应管理端用户管理的创建接口（POST）：录入姓名、邮箱、初始密码（密文），并至少绑定一个
 * 处于启用状态的角色。密码以密文提交（配合 password_key_id），避免明文口令进入请求体/日志。
 */
class AdminUserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * 新建管理员校验规则。
     *
     * - name：姓名，required。
     * - email：登录邮箱，email 格式 + unique:admin_users,email 全局唯一（邮箱是登录标识，不可重复）。
     * - password_encrypted：初始密码密文，required（长度上限 4096 以容纳密文）。
     * - password_key_id：加密所用密钥标识，size:16 精确 16 位，服务端据此解密。
     * - is_active：是否启用，sometimes 表示不传则走默认（新建时可缺省）。
     * - role_ids：必须绑定角色，required + array + min:1 —— 至少一个角色，禁止创建「无任何角色」的空权限账号。
     * - role_ids.*：每个角色 id 须为整数，且 Rule::exists(...)->where('is_active', true) 要求该角色
     *   不仅存在、还必须处于「启用」状态；防止把已停用的角色绑给新用户而带来意外授权。
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'], // 姓名，必填
            'email' => ['required', 'email', 'max:255', 'unique:admin_users,email'], // 登录邮箱，格式合法且全局唯一
            'password_encrypted' => ['required', 'string', 'max:4096'], // 初始密码密文
            'password_key_id' => ['required', 'string', 'size:16'], // 加密密钥标识，固定 16 位
            'is_active' => ['sometimes', 'boolean'], // 启用状态，未提交则走默认
            'role_ids' => ['required', 'array', 'min:1'], // 至少绑定一个角色，禁止空权限账号
            'role_ids.*' => [
                'integer',
                Rule::exists('admin_roles', 'id')->where('is_active', true), // 角色须存在且处于启用状态
            ],
        ];
    }
}
