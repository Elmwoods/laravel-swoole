<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 入站 Webhook 告警校验（token 守卫在控制器，authorize 放行）。
 */
class AlertIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'severity' => ['required', Rule::in(['critical', 'warning', 'info'])],
            'title' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9_:.\\-]+$/'],
            'dedup_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
