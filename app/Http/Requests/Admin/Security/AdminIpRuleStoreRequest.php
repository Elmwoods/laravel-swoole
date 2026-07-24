<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminIpRuleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['allow', 'deny'])],
            'cidr' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
