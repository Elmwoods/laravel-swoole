<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\OpsConfirmService;
use Illuminate\Validation\Rule;

class SupervisorControlRequest extends SupervisorProcessRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'confirm_text' => ['required', 'string', Rule::in([OpsConfirmService::CONFIRM_TEXT])],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'confirm_text.required' => '高风险 Supervisor 操作需要输入确认短语。',
            'confirm_text.in' => '确认短语不正确，请输入 CONFIRM。',
        ]);
    }
}
