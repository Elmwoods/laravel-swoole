<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警标签设置验证：用于「为某条告警覆盖式设置标签集合」。
 * 采用整体替换语义——所以 tags 必须存在（哪怕为空数组，表示清空所有标签）。
 */
class AlertTagsRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin 鉴权/权限）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 标签集合的验证规则。
     *
     * tags 用 present（必须出现但允许为空数组），配合整体替换语义：传空数组即清空标签，
     *   而非「不传就保持不变」；最多 20 个标签。
     * 每个标签受正则 /^[A-Za-z0-9_:.\-]+$/ 约束：只允许字母、数字、下划线、冒号、点、连字符，
     *   即非空且不含空格或其他特殊字符，保证标签可安全用于键名/过滤/展示。
     */
    public function rules(): array
    {
        return [
            // 标签数组：必须出现（可为空数组=清空），最多 20 个。
            'tags' => ['present', 'array', 'max:20'],
            // 单个标签：字符串、最长 60，字符集限定为 字母/数字/_ : . - （不允许空格等）。
            'tags.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9_:.\\-]+$/'],
        ];
    }
}
