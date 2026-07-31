<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警指派请求验证。
 *
 * 对应运维中心「指派告警」操作：把某条告警指派给具体的处理人。
 * assigned_to 为必填，note 可选备注。
 */
class AlertAssignRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明：
     * - assigned_to：被指派人，required 必填；string + max:120 限制长度。
     *   正则 /^[\pL\pN@._\-\s]+$/u 采用 Unicode 白名单：\pL 任意语言字母（含中文姓名）、
     *   \pN 任意数字、以及 @ . _ - 和空白字符，允许「姓名 / 邮箱 / 账号」等形式，
     *   同时排除引号、尖括号等可能引发注入或展示异常的特殊字符（u 修饰符启用 Unicode 匹配）。
     * - note：指派备注，nullable 可空；max:500 限制长度。
     */
    public function rules(): array
    {
        return [
            // 被指派人：必填，白名单正则仅允许字母/数字/@._-/空白，兼容中文姓名与邮箱
            'assigned_to' => ['required', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            // 备注：可空字符串，最长 500 字符
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
