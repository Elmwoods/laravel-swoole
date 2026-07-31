<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 告警静默（Silence）创建验证：用于新建一条「在指定时间窗内抑制告警通知」的规则。
 * 支持一次性窗口，以及按天/按周重复的时段静默，并可按来源、严重级别缩小匹配范围。
 */
class AlertSilenceStoreRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin 鉴权/权限）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 静默规则的验证规则。
     *
     * starts_at/ends_at 界定整体生效区间，ends_at 必须晚于 starts_at（after 约束）。
     * recurrence 用 Rule::in 限定为 once/daily/weekly 三种合法取值（白名单，防止未知重复类型）。
     * start_time/end_time 只有在 recurrence 为 daily 或 weekly 时才必填（required_if），
     *   因为一次性静默用不到每日时段；weekly 还额外要求 days_of_week（星期几）必填。
     * sources/severities 均可选：不填即不按该维度过滤（对全部来源/级别生效）；
     *   severities.* 用 Rule::in 白名单限定为 critical/warning/info。
     * is_active 用 sometimes：仅当请求带该字段时才校验，缺省由模型默认值决定。
     */
    public function rules(): array
    {
        return [
            // 备注标签：可选，最长 120 字符。
            'label' => ['nullable', 'string', 'max:120'],
            // 静默生效起始时间：必填，需为合法日期。
            'starts_at' => ['required', 'date'],
            // 静默结束时间：必填日期，且必须晚于起始时间。
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // 重复类型：可缺省；出现时只接受 once/daily/weekly 白名单值。
            'recurrence' => ['sometimes', Rule::in(['once', 'daily', 'weekly'])],
            // 每日/每周静默的起始时刻（HH:mm）：仅在 daily 或 weekly 时必填。
            'start_time' => ['nullable', 'date_format:H:i', 'required_if:recurrence,daily', 'required_if:recurrence,weekly'],
            // 每日/每周静默的结束时刻（HH:mm）：仅在 daily 或 weekly 时必填。
            'end_time' => ['nullable', 'date_format:H:i', 'required_if:recurrence,daily', 'required_if:recurrence,weekly'],
            // 生效星期集合：仅 weekly 时必填。
            'days_of_week' => ['nullable', 'array', 'required_if:recurrence,weekly'],
            // 星期取值：0-6（0 表示周日，6 表示周六）。
            'days_of_week.*' => ['integer', 'between:0,6'],
            // 限定生效的告警来源集合：可选，不填即匹配全部来源。
            'sources' => ['nullable', 'array'],
            // 单个来源标识：字符串，最长 60 字符。
            'sources.*' => ['string', 'max:60'],
            // 限定生效的严重级别集合：可选，不填即匹配全部级别。
            'severities' => ['nullable', 'array'],
            // 单个严重级别：白名单限定为 critical/warning/info。
            'severities.*' => [Rule::in(['critical', 'warning', 'info'])],
            // 是否立即启用：可缺省（sometimes），缺省时用模型默认值。
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
