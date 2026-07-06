<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Docker 容器操作请求验证
 *
 * 说明：
 * - 容器 ID 由路由参数传入，例如 /api/ops/docker/stats/{id}。
 * - 这里只允许容器短 ID、完整 ID 或常见容器名称字符，避免任意字符串进入 Docker API 路径。
 * - 业务权限后续可在 authorize 中接入管理员 RBAC。
 */
class DockerContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 将路由参数 id 放入验证数据。
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => $this->route('id'),
        ]);
    }

    /**
     * Docker 容器 ID/名称校验规则。
     */
    public function rules(): array
    {
        return [
            'id' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9_.:-]+$/',
            ],
        ];
    }

    /**
     * 中文验证提示，便于前端直接展示。
     */
    public function messages(): array
    {
        return [
            'id.required' => 'Docker 容器 ID 不能为空。',
            'id.regex' => 'Docker 容器 ID 格式不合法。',
            'id.max' => 'Docker 容器 ID 不能超过 128 个字符。',
        ];
    }

    /**
     * 获取已验证的容器 ID。
     */
    public function containerId(): string
    {
        return (string) $this->validated('id');
    }
}
