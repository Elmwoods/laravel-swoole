<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\OpsConfirmService;
use Illuminate\Validation\Rule;

/**
 * Docker 高风险操作（启动/停止/重启等）请求验证。
 * 继承 DockerContainerRequest 的容器 ID 校验，并额外强制要求二次确认短语，
 * 作为对不可逆或影响服务操作的「防误触」闸门。
 */
class DockerControlRequest extends DockerContainerRequest
{
    /**
     * 在父类容器 ID 规则基础上，追加确认短语校验。
     * confirm_text 必须精确等于 OpsConfirmService::CONFIRM_TEXT（用 Rule::in 单值白名单），
     * 只有输入正确短语才能放行高风险操作。
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // 确认短语：必填字符串，且必须与预设确认文案完全一致方可执行。
            'confirm_text' => ['required', 'string', Rule::in([OpsConfirmService::CONFIRM_TEXT])],
        ]);
    }

    /**
     * 合并父类文案，并补充确认短语相关的中文报错提示。
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            // 未输入确认短语时的提示。
            'confirm_text.required' => '高风险 Docker 操作需要输入确认短语。',
            // 输入的短语与预设不一致时的提示。
            'confirm_text.in' => '确认短语不正确，请输入 CONFIRM。',
        ]);
    }
}
