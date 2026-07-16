<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\OpsConfirmService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpsConfirmActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm_text' => ['required', 'string', Rule::in([OpsConfirmService::CONFIRM_TEXT])],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_text.required' => '高风险操作需要输入确认短语。',
            'confirm_text.in' => '确认短语不正确，请输入 CONFIRM。',
        ];
    }
}
