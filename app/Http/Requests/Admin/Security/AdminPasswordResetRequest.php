<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 后台「管理员密码重置」请求。
 *
 * 对应管理端重置某管理员密码的接口。注意：前端提交的是「已加密的密文」而非明文口令 ——
 * 新密码与确认密码都以密文形式传输（配合 password_key_id 指定的密钥在服务端解密），
 * 避免明文密码经由请求体/日志泄露。
 */
class AdminPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * 密码重置校验规则。
     *
     * - password_encrypted：新密码的密文，required + string，长度上限 4096（密文比明文长，留足空间）。
     * - password_confirmation_encrypted：确认密码的密文；与新密码是否一致的比对在服务端解密后进行，
     *   故此处不能用 Laravel 的 confirmed 规则（confirmed 针对明文 xxx / xxx_confirmation，不适用于两段独立密文）。
     * - password_key_id：本次加密所用密钥的标识，size:16 精确限定 16 位，服务端据此定位对应解密密钥。
     */
    public function rules(): array
    {
        return [
            'password_encrypted' => ['required', 'string', 'max:4096'], // 新密码密文
            'password_confirmation_encrypted' => ['required', 'string', 'max:4096'], // 确认密码密文，一致性在服务端比对
            'password_key_id' => ['required', 'string', 'size:16'], // 加密密钥标识，固定 16 位
        ];
    }
}
