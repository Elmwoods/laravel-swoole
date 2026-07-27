<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AlertSilenceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'max:60'],
            'severities' => ['nullable', 'array'],
            'severities.*' => [Rule::in(['critical', 'warning', 'info'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
