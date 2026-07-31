<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台「新增 IP 准入规则」请求。
 *
 * 对应管理端「登录准入」里新增一条 IP 规则的接口（POST）：指定放行/拒绝、
 * 目标 CIDR 网段，以及可选备注。每条规则直接影响哪些来源 IP 能登录后台。
 */
class AdminIpRuleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * IP 规则校验规则。
     *
     * - type：规则类型，Rule::in 白名单仅允许 allow（放行）/ deny（拒绝）两种动作，
     *   杜绝写入无法识别的动作导致准入判定出现未定义行为。
     * - cidr：目标网段/地址（如 10.0.0.0/8 或单 IP），此处只校验必填与长度上限（≤64），
     *   具体的 CIDR 合法性由服务层解析时进一步保证。
     * - label：人类可读备注，可空，仅限长度。
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['allow', 'deny'])], // 动作枚举白名单：放行/拒绝
            'cidr' => ['required', 'string', 'max:64'], // 目标 CIDR/IP，长度上限 64
            'label' => ['nullable', 'string', 'max:120'], // 可选备注
        ];
    }
}
