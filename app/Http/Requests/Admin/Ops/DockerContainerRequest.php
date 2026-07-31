<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Docker 容器操作请求验证
 *
 * 说明：
 * - 容器 ID 由路由参数传入，例如 /api/ops/docker/stats/{id}。
 * - 这里只允许容器短 ID、完整 ID 或常见容器名称字符，避免任意字符串进入 Docker API 路径。
 * - RBAC 已由 admin.auth 与 admin.permission 中间件统一处理。
 */
class DockerContainerRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin.auth / admin.permission）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 将路由参数 id 放入验证数据。
     */
    protected function prepareForValidation(): void
    {
        // 容器 ID 来自 URL 路由段而非请求体，先合并进待验证数据，才能被下面的 rules() 校验。
        $this->merge([
            'id' => $this->route('id'),
        ]);
    }

    /**
     * Docker 容器 ID/名称校验规则。
     *
     * 正则 /^[A-Za-z0-9_.:-]+$/ 只放行字母、数字、下划线、点、冒号、连字符，
     * 即容器短 ID、完整 ID 或常见容器名的合法字符集；借此过滤斜杠、空格等，
     * 防止任意字符串被拼接进 Docker API 路径造成注入或越权访问其他资源。
     */
    public function rules(): array
    {
        return [
            'id' => [
                'required', // 容器 ID 必填
                'string',   // 必须是字符串
                'max:128',  // 长度上限 128（足够容纳完整 64 位 ID 及常见名称）
                'regex:/^[A-Za-z0-9_.:-]+$/', // 仅允许安全字符集，杜绝路径穿越/注入
            ],
        ];
    }

    /**
     * 中文验证提示，便于前端直接展示。
     */
    public function messages(): array
    {
        return [
            // 各校验规则对应的中文报错文案，键为「字段.规则名」。
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
        // 便捷取值方法：只返回已通过校验的 id，供 controller 安全使用。
        return (string) $this->validated('id');
    }
}
