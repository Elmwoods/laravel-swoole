<?php

namespace App\Http\Requests\Admin\Ops;

use App\Services\Ops\AlertRuleRegistryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 告警规则更新验证：用于「编辑某条已注册告警规则的阈值/启停」。
 * 规则的元数据（key/name/source 等）由注册表定义、不可改，这里只允许调整阈值与开关；
 * 合法阈值区间从 AlertRuleRegistryService 的规则定义动态读取。
 */
class AlertRuleUpdateRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin 鉴权/权限）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 阈值更新的验证规则。
     *
     * key/name/source/metric/operator/unit 全部标记为 prohibited（禁止出现），
     * 因为这些属于规则的不可变元数据，只能由注册表定义，防止前端越权篡改。
     * 预警/严重阈值的 min/max 从当前规则定义动态取得（缺省 0 与 1000000），
     * 保证输入落在该规则业务上允许的取值区间内。
     */
    public function rules(): array
    {
        // 依据路由参数 adminRule 取出该规则的注册定义，从中读取合法阈值区间。
        $definition = app(AlertRuleRegistryService::class)
            ->definition((string) $this->route('adminRule'));
        $min = $definition['min'] ?? 0;
        $max = $definition['max'] ?? 1000000;

        return [
            // 以下六项均为规则的固有元数据，禁止在更新请求中携带（prohibited=一旦出现即校验失败）。
            'key' => ['prohibited'],
            'name' => ['prohibited'],
            'source' => ['prohibited'],
            'metric' => ['prohibited'],
            'operator' => ['prohibited'],
            'unit' => ['prohibited'],
            // 预警阈值：必填数值，且限制在该规则定义的 [min, max] 区间内。
            'warning_threshold' => ['required', 'numeric', "min:{$min}", "max:{$max}"],
            // 严重阈值：可为 null（仅设预警级），存在时同样受 [min, max] 约束。
            'critical_threshold' => ['nullable', 'numeric', "min:{$min}", "max:{$max}"],
            // 是否启用：必填布尔值。
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * 规则校验通过后的附加跨字段校验（after 钩子）。
     * 用于保证严重阈值不低于预警阈值——单条 rules 规则无法表达两字段间的大小关系。
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $warning = $this->input('warning_threshold');
                $critical = $this->input('critical_threshold');

                // 仅在设置了严重阈值时比较；严重阈值小于预警阈值属逻辑错误，需拦截。
                if ($critical !== null && (float) $critical < (float) $warning) {
                    $validator->errors()->add('critical_threshold', '严重阈值必须大于或等于预警阈值。');
                }
            },
        ];
    }
}
