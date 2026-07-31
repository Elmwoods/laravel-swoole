<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警规则导入验证：仅校验结构，细粒度 min/max 与白名单在 service 内按 key 逐条判定。
 */
class AlertRuleImportRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin 鉴权/权限）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 批量导入告警规则的验证规则。
     *
     * 仅做结构性校验（是否存在、类型、上限），不校验每条规则的业务取值范围；
     * 细粒度的 min/max 与白名单（key 是否合法）留给 service 层按 key 逐条判定，
     * 因为不同规则的合法阈值区间各不相同，无法在此统一表达。
     */
    public function rules(): array
    {
        return [
            // 导入负载必须是数组，且最多 200 条，防止单次请求塞入过量规则拖垮处理。
            'rules' => ['required', 'array', 'max:200'],
            // 每条规则的 key（规则标识），非空字符串，最长 80 字符。
            'rules.*.key' => ['required', 'string', 'max:80'],
            // 预警阈值：必填且必须是数值（具体上下限由 service 按 key 判定）。
            'rules.*.warning_threshold' => ['required', 'numeric'],
            // 严重阈值：可为 null（部分规则只有预警级），存在时必须是数值。
            'rules.*.critical_threshold' => ['nullable', 'numeric'],
            // 是否启用：必填布尔值。
            'rules.*.is_active' => ['required', 'boolean'],
        ];
    }
}
