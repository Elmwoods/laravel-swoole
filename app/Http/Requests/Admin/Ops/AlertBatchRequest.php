<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 分组批量操作验证：by=source|severity + group；assign 需 assigned_to；silence 需 minutes。
 */
class AlertBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'by' => ['required', 'string', 'in:source,severity'],
            'group' => ['required', 'string', 'max:120'],
            'assigned_to' => ['nullable', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            'note' => ['nullable', 'string', 'max:500'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
