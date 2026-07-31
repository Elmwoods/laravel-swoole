<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 分组批量操作验证：by=source|severity + group；assign 需 assigned_to；silence 需 minutes。
 *
 * 对应运维中心「按分组批量处理告警」操作：先用 by + group 圈定一批同类告警，
 * 再对这批告警统一执行确认 / 指派 / 静默等动作。
 * assigned_to 与 minutes 是「按需字段」——仅在对应动作下才有意义，故此处均设为 nullable，
 * 是否真正必填由控制器根据具体动作决定。
 */
class AlertBatchRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明：
     * - by：分组维度，required 必填；in:source,severity 白名单只允许「按来源」或「按严重级别」分组，
     *   防止前端传入任意列名导致后端按未知维度查询。
     * - group：分组取值，required 必填；max:120 限制长度（例如具体的 source 名或 severity 值）。
     * - assigned_to：批量指派时的处理人，nullable（仅 assign 动作使用）；白名单正则同 AlertAssignRequest，
     *   \pL/\pN 允许中文姓名与数字，附加 @._- 与空白，排除特殊字符。
     * - note：批量操作备注，nullable 可空；max:500 限制长度。
     * - minutes：批量静默时长（分钟），nullable（仅 silence 动作使用）；integer 且 min:1 max:1440，
     *   即 1 分钟到 24 小时，避免 0/负数或过长的静默窗口。
     */
    public function rules(): array
    {
        return [
            // 分组维度：仅允许 source 或 severity 两种白名单值
            'by' => ['required', 'string', 'in:source,severity'],
            // 分组取值：必填，最长 120 字符
            'group' => ['required', 'string', 'max:120'],
            // 批量指派处理人：可空（仅指派动作用到），白名单正则兼容中文姓名与邮箱
            'assigned_to' => ['nullable', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            // 备注：可空字符串，最长 500 字符
            'note' => ['nullable', 'string', 'max:500'],
            // 静默时长：可空（仅静默动作用到），范围 1~1440 分钟（最长 24 小时）
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
