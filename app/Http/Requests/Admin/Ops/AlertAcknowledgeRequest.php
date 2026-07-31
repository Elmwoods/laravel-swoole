<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警确认请求验证。
 *
 * 对应运维中心「确认告警」操作（PATCH/POST 将某条 open 告警标记为 acknowledged）。
 * 两个字段均为可选，确认动作本身不强制填写操作人或备注。
 */
class AlertAcknowledgeRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明：
     * - acknowledged_by：确认人标识，nullable 表示可省略（省略时后端通常回落到当前登录用户）；
     *   string + max:120 限制长度，防止超长字符串写入数据库。
     * - note：确认备注，nullable 可空；max:500 限制备注长度，避免存储过长文本。
     */
    public function rules(): array
    {
        return [
            // 确认人：可空字符串，最长 120 字符
            'acknowledged_by' => ['nullable', 'string', 'max:120'],
            // 备注：可空字符串，最长 500 字符
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
