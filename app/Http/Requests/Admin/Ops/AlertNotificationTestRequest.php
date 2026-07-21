<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 告警通知测试请求验证。
 *
 * 只允许测试受支持的通知通道，避免前端传入任意字符串影响后端逻辑。
 * 支持通道单一来源于 config('ops.alerts.channels')，与 Service 白名单同源。
 */
class AlertNotificationTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channels = (array) config('ops.alerts.channels', ['telegram', 'mail']);

        return [
            'channels' => ['nullable', 'array', 'max:'.max(1, count($channels))],
            'channels.*' => ['string', Rule::in($channels)],
            'message' => ['nullable', 'string', 'max:300'],
        ];
    }
}
