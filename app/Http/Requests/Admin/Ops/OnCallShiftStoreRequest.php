<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnCallShiftStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee' => ['required', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            'label' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'recurrence' => ['sometimes', Rule::in(['once', 'daily', 'weekly'])],
            'start_time' => ['nullable', 'date_format:H:i', 'required_if:recurrence,daily', 'required_if:recurrence,weekly'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_if:recurrence,daily', 'required_if:recurrence,weekly'],
            'days_of_week' => ['nullable', 'array', 'required_if:recurrence,weekly'],
            'days_of_week.*' => ['integer', 'between:0,6'],
        ];
    }
}
