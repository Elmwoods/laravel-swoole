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
     * RBAC 已由 admin.auth 与 admin.permission 中间件统一处理。
     *
     * 返回 true 表示本 Request 不做鉴权，授权完全交给路由中间件（admin.auth / admin.permission）。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 将路由参数合并到待验证数据中。
     *
     * 进程名通过 URL 路径段传入（如 /supervisor/restart/octane），请求体里并没有 name 字段，
     * 这里在验证前把路由参数 name 合并进入待校验数据，使其能走下面 rules() 的正则/长度校验，
     * 从而对来自 URL 的不可信输入同样做安全过滤。
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            // 把路由里的进程名塞进待验证数据，纳入统一校验
            'name' => $this->route('name'),
        ]);
    }

    /**
     * 定义 Supervisor 进程名规则。
     *
     * name 必填、字符串、长度上限 120；正则模式不写死在代码里，而是从配置
     * ops.supervisor.process_allow_pattern 读取，便于按部署环境调整允许的进程名字符集
     * （通常放行字母数字与 _ . : - 以兼容 Supervisor 的 group:process 命名），
     * 以此把可控进程限定在白名单模式内，防止路径穿越或命令注入。
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                // 正则来自配置，按环境定义允许的进程名字符集（白名单）
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
     *
     * 从 validated() 取值，确保 Controller 只能拿到经过上面规则校验后的安全进程名。
     */
    public function serviceName(): string
    {
        return (string) $this->validated('name');
    }
}
