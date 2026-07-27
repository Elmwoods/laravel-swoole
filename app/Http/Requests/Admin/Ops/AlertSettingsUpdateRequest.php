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
        $channels = (array) config('ops.alerts.channels', ['telegram', 'mail']);
        $severities = ['critical', 'warning', 'info'];

        $rules = [
            'notification_repeat_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'auto_resolve_enabled' => ['required', 'boolean'],
            'auto_resolve_grace_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'escalation_enabled' => ['required', 'boolean'],
            'escalation_after_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'message_template' => ['nullable', 'string', 'max:2000'],
            'severity_channels' => ['required', 'array'],
        ];

        foreach ($channels as $channel) {
            $rules["{$channel}_enabled"] = ['required', 'boolean'];
        }

        foreach ($severities as $severity) {
            $rules["severity_channels.{$severity}"] = ['required', 'array'];

            foreach ($channels as $channel) {
                $rules["severity_channels.{$severity}.{$channel}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }
}
