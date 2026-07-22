<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAuditPresetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验预设名 + 嵌套 filters，各字段镜像 AdminAuditIndexRequest。
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'filters' => ['nullable', 'array'],
            'filters.admin_user_id' => ['nullable', 'integer', 'exists:admin_users,id'],
            'filters.module' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'],
            'filters.action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'],
            'filters.result' => ['nullable', Rule::in(['success', 'failure'])],
            'filters.keyword' => ['nullable', 'string', 'max:120'],
            'filters.status_code' => ['nullable', 'integer', 'min:100', 'max:599'],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date', 'after_or_equal:filters.from'],
        ];
    }
}
