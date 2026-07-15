<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $adminUser = $this->route('adminUser');

        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('admin_users', 'email')->ignore($adminUser?->id),
            ],
            'is_active' => ['required', 'boolean'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => [
                'integer',
                Rule::exists('admin_roles', 'id')->where('is_active', true),
            ],
        ];
    }
}
