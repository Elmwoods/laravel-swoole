<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警备注创建请求验证。
 *
 * 对应运维中心「给某条告警追加一条备注/处理记录」的接口（POST 一条 note）。
 * 仅一个必填正文字段 body。
 */
class AlertNoteStoreRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明：
     * - body：备注正文，required 必填（空备注无意义）；string + max:2000 限制单条备注长度。
     */
    public function rules(): array
    {
        return [
            // 备注正文：必填字符串，最长 2000
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
