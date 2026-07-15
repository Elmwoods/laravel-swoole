<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:admin_users,email'],
            'password_encrypted' => ['required', 'string', 'max:4096'],
            'password_key_id' => ['required', 'string', 'size:16'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => [
                'integer',
                Rule::exists('admin_roles', 'id')->where('is_active', true),
            ],
        ];
    }
}
