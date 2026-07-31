<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 值班排班（On-Call Shift）创建请求验证。
 *
 * 用于运维中心新增一条值班安排的接口：指定值班人、时间区间，以及可选的
 * 周期性规则（一次性 / 每日 / 每周）。周期为每日或每周时需要补充每日的起止时间，
 * 每周还需指定生效的星期几。
 */
class OnCallShiftStoreRequest extends FormRequest
{
    /**
     * 返回 true 表示不在此处鉴权，授权由路由中间件（admin.auth / admin.permission）统一处理。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 值班排班创建规则。
     *
     * - assignee：值班人，必填；regex /^[\pL\pN@._\-\s]+$/u 中 \pL=任意语言字母、\pN=任意数字，
     *   额外放行 @ . _ - 与空白，u 修饰符启用 Unicode，从而兼容中文姓名、邮箱、带空格的显示名，
     *   同时拒绝其它特殊字符以防注入。
     * - label：可选的排班备注/标签，长度上限 120。
     * - starts_at / ends_at：排班整体生效区间，均必填且须为合法日期；ends_at 的 after:starts_at
     *   保证结束晚于开始，避免空/倒置区间。
     * - recurrence：周期类型，sometimes 表示仅当字段出现时才校验（缺省即不限定，走默认一次性语义）；
     *   Rule::in 白名单只允许 once / daily / weekly 三种合法周期，杜绝任意值。
     * - start_time / end_time：每日的值班起止时刻，date_format:H:i 限定 24 小时制 HH:mm；
     *   required_if:recurrence,daily 与 required_if:recurrence,weekly 表示仅当周期为每日或每周时才必填
     *   （一次性排班用不到每日时刻，故设为 nullable）。
     * - days_of_week：每周生效的星期集合，required_if:recurrence,weekly 表示仅每周周期时必填。
     * - days_of_week.*：数组每个元素须为整数且 between:0,6，对应周日(0)~周六(6)。
     */
    public function rules(): array
    {
        return [
            // 值班人：必填，Unicode 正则兼容中文名/邮箱/带空格显示名
            'assignee' => ['required', 'string', 'max:120', 'regex:/^[\\pL\\pN@._\\-\\s]+$/u'],
            // 排班标签：可选备注
            'label' => ['nullable', 'string', 'max:120'],
            // 排班开始时间：必填合法日期
            'starts_at' => ['required', 'date'],
            // 排班结束时间：必填且必须晚于开始时间
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // 周期类型：仅当传入时校验，白名单 once/daily/weekly
            'recurrence' => ['sometimes', Rule::in(['once', 'daily', 'weekly'])],
            // 每日开始时刻：HH:mm；每日/每周周期时必填
            'start_time' => ['nullable', 'date_format:H:i', 'required_if:recurrence,daily', 'required_if:recurrence,weekly'],
            // 每日结束时刻：HH:mm；每日/每周周期时必填
            'end_time' => ['nullable', 'date_format:H:i', 'required_if:recurrence,daily', 'required_if:recurrence,weekly'],
            // 生效星期集合：每周周期时必填的数组
            'days_of_week' => ['nullable', 'array', 'required_if:recurrence,weekly'],
            // 星期元素：整数 0~6，对应周日到周六
            'days_of_week.*' => ['integer', 'between:0,6'],
        ];
    }
}
