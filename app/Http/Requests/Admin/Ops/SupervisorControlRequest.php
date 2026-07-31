<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\OpsConfirmService;
use Illuminate\Validation\Rule;

/**
 * 高风险 Supervisor 控制操作请求验证。
 *
 * 在父类 SupervisorProcessRequest（校验进程名）的基础上，追加二次确认短语校验，
 * 用于重启/停止等危险的 Supervisor 控制操作接口——既要求合法进程名，也要求输入确认短语。
 * 授权同样沿用父类：由路由中间件统一处理。
 */
class SupervisorControlRequest extends SupervisorProcessRequest
{
    /**
     * 在父类进程名规则之上，叠加确认短语规则。
     *
     * array_merge(parent::rules(), ...) 保留父类对 name 的校验，再新增 confirm_text：
     * 必填，且 Rule::in 白名单限定其只能等于 OpsConfirmService::CONFIRM_TEXT（当前为 CONFIRM），
     * 确保危险操作必须人工确认。
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // 确认短语：必填，且必须精确等于 Service 中约定的确认文本
            'confirm_text' => ['required', 'string', Rule::in([OpsConfirmService::CONFIRM_TEXT])],
        ]);
    }

    /**
     * 在父类提示之上，追加确认短语相关的中文提示。
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            // 未输入确认短语
            'confirm_text.required' => '高风险 Supervisor 操作需要输入确认短语。',
            // 输入的确认短语与约定值不符
            'confirm_text.in' => '确认短语不正确，请输入 CONFIRM。',
        ]);
    }
}
