<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Supervisor 进程操作请求验证
 *
 * 说明：
 * - Supervisor 的进程名来自路由参数，例如 /api/ops/supervisor/restart/octane。
 * - 这里不把验证写在 Controller 中，保持 Laravel 的 Request 独立验证规范。
 * - 允许字母、数字、下划线、点、冒号、短横线，兼容 Supervisor group:process 格式。
 */
class SupervisorProcessRequest extends FormRequest
{
    /**
     * Ops Center 暂未接入权限系统，因此先允许请求通过。
     * 后续接入 Sanctum/后台权限时，可在这里校验当前管理员是否具备运维权限。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 将路由参数合并到待验证数据中。
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->route('name'),
        ]);
    }

    /**
     * 定义 Supervisor 进程名规则。
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:'.config('ops.supervisor.process_allow_pattern'),
            ],
        ];
    }

    /**
     * 中文验证提示，方便后台直接展示。
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Supervisor 进程名不能为空。',
            'name.regex' => 'Supervisor 进程名格式不合法。',
            'name.max' => 'Supervisor 进程名不能超过 120 个字符。',
        ];
    }

    /**
     * 获取已验证的 Supervisor 进程名。
     */
    public function serviceName(): string
    {
        return (string) $this->validated('name');
    }
}
