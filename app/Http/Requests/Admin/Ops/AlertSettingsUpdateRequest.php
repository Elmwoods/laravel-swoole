<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

class AlertSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notification_repeat_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'auto_resolve_enabled' => ['required', 'boolean'],
            'auto_resolve_grace_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'telegram_enabled' => ['required', 'boolean'],
            'mail_enabled' => ['required', 'boolean'],
            'severity_channels' => ['required', 'array'],
            'severity_channels.critical' => ['required', 'array'],
            'severity_channels.warning' => ['required', 'array'],
            'severity_channels.info' => ['required', 'array'],
            'severity_channels.critical.telegram' => ['required', 'boolean'],
            'severity_channels.critical.mail' => ['required', 'boolean'],
            'severity_channels.warning.telegram' => ['required', 'boolean'],
            'severity_channels.warning.mail' => ['required', 'boolean'],
            'severity_channels.info.telegram' => ['required', 'boolean'],
            'severity_channels.info.mail' => ['required', 'boolean'],
        ];
    }
}
