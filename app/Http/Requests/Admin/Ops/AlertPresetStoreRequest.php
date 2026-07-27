<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AlertPresetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验预设名 + 嵌套 filters，各字段镜像 AlertIndexRequest。
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'filters' => ['nullable', 'array'],
            'filters.status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
            'filters.severity' => ['nullable', Rule::in(['critical', 'warning', 'info'])],
            'filters.source' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
