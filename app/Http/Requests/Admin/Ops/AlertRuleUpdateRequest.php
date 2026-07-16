<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AlertRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $definition = app(AlertRuleRegistryService::class)
            ->definition((string) $this->route('adminRule'));
        $min = $definition['min'] ?? 0;
        $max = $definition['max'] ?? 1000000;

        return [
            'key' => ['prohibited'],
            'name' => ['prohibited'],
            'source' => ['prohibited'],
            'metric' => ['prohibited'],
            'operator' => ['prohibited'],
            'unit' => ['prohibited'],
            'warning_threshold' => ['required', 'numeric', "min:{$min}", "max:{$max}"],
            'critical_threshold' => ['nullable', 'numeric', "min:{$min}", "max:{$max}"],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $warning = $this->input('warning_threshold');
                $critical = $this->input('critical_threshold');

                if ($critical !== null && (float) $critical < (float) $warning) {
                    $validator->errors()->add('critical_threshold', '严重阈值必须大于或等于预警阈值。');
                }
            },
        ];
    }
}
