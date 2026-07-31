<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 后台管理员登录请求验证。
 *
 * 用于管理员登录接口。为避免明文密码在网络中传输，前端先用某个密钥对密码加密，
 * 再把「加密后的密码」与「所用密钥的标识」一起提交，后端据 key_id 找到对应密钥解密后再校验。
 */
class AdminLoginRequest extends FormRequest
{
    /**
     * 登录接口对所有访客开放，无需在此鉴权，故直接返回 true。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 登录参数规则。
     *
     * - email：必填，且须为合法邮箱格式，长度上限 255（数据库常见列长）。
     * - password_encrypted：前端加密后的密码密文，必填字符串；上限 4096 是为密文
     *   （通常比明文长、可能含 Base64 编码）预留足够空间，同时防止超大负载。
     * - password_key_id：本次加密所用密钥的标识，必填且 size:16 精确要求 16 个字符，
     *   与后端签发的固定长度密钥 ID 一致，便于后端据此定位解密密钥。
     */
    public function rules(): array
    {
        return [
            // 登录邮箱：必填、合法邮箱格式、上限 255
            'email' => ['required', 'email', 'max:255'],
            // 加密后的密码密文：必填，上限 4096 以容纳密文
            'password_encrypted' => ['required', 'string', 'max:4096'],
            // 加密密钥标识：必填，长度精确 16 位，用于后端定位解密密钥
            'password_key_id' => ['required', 'string', 'size:16'],
        ];
    }
}
