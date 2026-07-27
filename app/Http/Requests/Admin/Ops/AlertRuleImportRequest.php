<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警规则导入验证：仅校验结构，细粒度 min/max 与白名单在 service 内按 key 逐条判定。
 */
class AlertRuleImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'max:200'],
            'rules.*.key' => ['required', 'string', 'max:80'],
            'rules.*.warning_threshold' => ['required', 'numeric'],
            'rules.*.critical_threshold' => ['nullable', 'numeric'],
            'rules.*.is_active' => ['required', 'boolean'],
        ];
    }
}
