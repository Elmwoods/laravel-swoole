<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警通知测试请求验证。
 *
 * 只允许测试受支持的通知通道，避免前端传入任意字符串影响后端逻辑。
 */
class AlertNotificationTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channels' => ['nullable', 'array', 'max:2'],
            'channels.*' => ['string', 'in:telegram,mail'],
            'message' => ['nullable', 'string', 'max:300'],
        ];
    }
}
