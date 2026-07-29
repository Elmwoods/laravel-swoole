<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

class ShiftHandoverStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_assignee' => ['nullable', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            'to_assignee' => ['nullable', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
