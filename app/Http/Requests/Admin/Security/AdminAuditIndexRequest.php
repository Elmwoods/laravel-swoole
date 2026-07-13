<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminAuditIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_user_id' => ['nullable', 'integer', 'exists:admin_users,id'],
            'module' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'],
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'],
            'result' => ['nullable', Rule::in(['success', 'failure'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
