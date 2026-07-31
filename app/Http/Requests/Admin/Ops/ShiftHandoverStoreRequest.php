<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 值班交接记录（Shift Handover）创建请求验证。
 *
 * 用于记录一次值班交接：由谁交给谁，以及交接备注。交接双方均为可选，
 * 以适配「无明确前任」或「仅登记交接说明」等场景。
 */
class ShiftHandoverStoreRequest extends FormRequest
{
    /**
     * 返回 true 表示不在此处鉴权，授权由路由中间件（admin.auth / admin.permission）统一处理。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 交接记录规则。
     *
     * - from_assignee / to_assignee：交出方与接手方，均可空（nullable）；
     *   regex /^[\pL\pN@._\-\s]+$/u 中 \pL=任意语言字母、\pN=任意数字，放行 @ . _ - 与空白，
     *   u 修饰符启用 Unicode，从而兼容中文姓名、邮箱及带空格的显示名，同时拒绝其它特殊字符。
     * - note：交接备注，可空，长度上限 2000，容纳较长的交接说明同时防止超大文本。
     */
    public function rules(): array
    {
        return [
            // 交出方：可空，Unicode 正则兼容中文名/邮箱/带空格显示名
            'from_assignee' => ['nullable', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            // 接手方：可空，字符集同上
            'to_assignee' => ['nullable', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            // 交接备注：可空，上限 2000 字符
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
