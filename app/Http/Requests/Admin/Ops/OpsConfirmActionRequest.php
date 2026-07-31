<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\OpsConfirmService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 高风险运维操作二次确认请求验证。
 *
 * 用于需要人工二次确认的危险操作接口（如重启、清理等）：要求调用方在请求体中
 * 输入约定的确认短语，输入正确才放行，防止误操作。确认短语的标准值来自
 * OpsConfirmService::CONFIRM_TEXT，集中管理避免各处硬编码不一致。
 */
class OpsConfirmActionRequest extends FormRequest
{
    /**
     * 返回 true 表示不在此处鉴权，授权由路由中间件（admin.auth / admin.permission）统一处理。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 确认短语校验规则。
     *
     * confirm_text 必填，且通过 Rule::in 限定只能等于 OpsConfirmService::CONFIRM_TEXT
     * 这个唯一合法值（当前为 CONFIRM）。用白名单而非固定字符串常量书写，
     * 是为了让确认短语的定义集中在 Service 常量中，前后端与校验保持一致。
     */
    public function rules(): array
    {
        return [
            // 确认短语：必填，且必须精确等于 Service 中约定的确认文本
            'confirm_text' => ['required', 'string', Rule::in([OpsConfirmService::CONFIRM_TEXT])],
        ];
    }

    /**
     * 中文验证提示，直接展示给操作者。
     */
    public function messages(): array
    {
        return [
            // 未输入确认短语
            'confirm_text.required' => '高风险操作需要输入确认短语。',
            // 输入的确认短语与约定值不符
            'confirm_text.in' => '确认短语不正确，请输入 CONFIRM。',
        ];
    }
}
