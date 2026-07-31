<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台「登录 IP 准入」全局开关设置请求。
 *
 * 对应管理端「IP 访问控制设置」接口（保存全局策略），控制 IP 准入总开关、
 * 黑/白名单工作模式，以及自动封禁开关。属于影响登录准入的高危安全配置。
 */
class AdminIpAccessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * IP 准入设置校验规则。
     *
     * - ip_access_enabled：准入总开关，required + boolean —— 必须明确传布尔值，
     *   避免因缺省被误解读为「关闭」而放开所有 IP。
     * - ip_access_mode：工作模式，Rule::in 白名单仅允许 blocklist（黑名单，命中即拒）
     *   / allowlist（白名单，仅命中放行）两种；用枚举白名单杜绝写入未知模式导致准入逻辑失控。
     * - auto_ban_enabled：自动封禁开关，sometimes 表示「只有当请求里带了这个键才校验」，
     *   便于只更新部分设置而不强制每次都提交该字段。
     */
    public function rules(): array
    {
        return [
            'ip_access_enabled' => ['required', 'boolean'], // 准入总开关，必须显式布尔
            'ip_access_mode' => ['required', Rule::in(['blocklist', 'allowlist'])], // 模式枚举白名单：黑名单/白名单
            'auto_ban_enabled' => ['sometimes', 'boolean'], // 仅当提交了该键才校验（支持部分更新）
        ];
    }
}
