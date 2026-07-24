<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminIpAccessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ip_access_enabled' => ['required', 'boolean'],
            'ip_access_mode' => ['required', Rule::in(['blocklist', 'allowlist'])],
        ];
    }
}
