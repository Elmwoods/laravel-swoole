<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

class AlertAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['required', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
