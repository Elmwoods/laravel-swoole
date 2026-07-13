<?php

namespace App\Http\Requests\Admin\Security;

use App\Models\AdminPermission;
use App\Services\Admin\AdminPermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminRoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->route('adminRole');

        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('admin_roles', 'slug')->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', Rule::exists('admin_permissions', 'id')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $invalid = AdminPermission::query()
                ->whereIn('id', $this->input('permission_ids', []))
                ->whereNotIn('slug', AdminPermissionRegistry::slugs())
                ->exists();

            if ($invalid) {
                $validator->errors()->add('permission_ids', '权限点不在系统白名单中。');
            }
        });
    }
}
