<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警规则启停切换验证：用于「启用/停用某条告警规则」这一动作的请求体。
 * 仅接收一个 is_active 布尔开关，规则本身由路由参数定位。
 */
class AlertRuleToggleRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin 鉴权/权限）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 切换启停状态的验证规则。
     */
    public function rules(): array
    {
        return [
            // 目标启用状态：必填布尔值（true=启用，false=停用）。
            'is_active' => ['required', 'boolean'],
        ];
    }
}
