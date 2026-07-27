<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警列表查询验证。
 */
class AlertIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:open,acknowledged,resolved'],
            'severity' => ['nullable', 'string', 'in:critical,warning,info'],
            'source' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'assigned_to' => ['nullable', 'string', 'max:120'],
            'assigned' => ['nullable', 'string', 'in:unassigned,any'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
