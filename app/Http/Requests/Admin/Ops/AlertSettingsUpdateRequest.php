<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警设置更新验证：用于保存运维告警的全局配置。
 * 覆盖通知重复间隔、自动解决、升级策略、消息模板，以及「各严重级别 × 各通道」的开关矩阵。
 * 可用通道来自 config('ops.alerts.channels')，因此规则集是按当前启用通道动态拼装的。
 */
class AlertSettingsUpdateRequest extends FormRequest
{
    // authorize() 返回 true 表示此处不做鉴权，访问控制由路由中间件（admin 鉴权/权限）统一负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 告警设置的验证规则（按当前通道配置动态生成）。
     *
     * 时间类字段统一用「分钟」并限制上限 1440（即 24 小时），避免设置出不合理的超长周期。
     * 严重级别固定为 critical/warning/info；每个级别下都要为每个通道给出布尔开关，
     * 构成 severity_channels[severity][channel] 的完整开关矩阵，保证前端提交无遗漏。
     */
    public function rules(): array
    {
        // 可用通道由配置决定（默认 telegram、mail）；后续所有按通道展开的规则都基于它。
        $channels = (array) config('ops.alerts.channels', ['telegram', 'mail']);
        // 三个固定的严重级别，用于生成开关矩阵。
        $severities = ['critical', 'warning', 'info'];

        $rules = [
            // 同一告警重复通知的最小间隔（分钟）；允许 0 表示不做重复抑制，上限 1440。
            'notification_repeat_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            // 是否开启自动解决。
            'auto_resolve_enabled' => ['required', 'boolean'],
            // 自动解决的宽限期（分钟）；至少 1 分钟，避免刚触发即被判定恢复。
            'auto_resolve_grace_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            // 是否开启升级（长时间未处理则升级）。
            'escalation_enabled' => ['required', 'boolean'],
            // 触发升级所需的等待时长（分钟），至少 1 分钟。
            'escalation_after_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            // 全局消息模板：可选，最长 2000 字符；为空时使用系统默认模板。
            'message_template' => ['nullable', 'string', 'max:2000'],
            // 严重级别 → 通道开关矩阵的根节点，必须是数组。
            'severity_channels' => ['required', 'array'],
        ];

        // 为每个启用的通道生成「{通道}_enabled」总开关（是否启用该通道）。
        foreach ($channels as $channel) {
            $rules["{$channel}_enabled"] = ['required', 'boolean'];
        }

        // 每通道独立模板（仅文本通道，webhook 除外）——可选，回退全局。
        foreach (array_diff($channels, ['webhook']) as $channel) {
            $rules["message_template_{$channel}"] = ['nullable', 'string', 'max:2000'];
        }

        // 展开「严重级别 × 通道」开关矩阵：每个级别是数组，级别下每个通道是必填布尔。
        foreach ($severities as $severity) {
            $rules["severity_channels.{$severity}"] = ['required', 'array'];

            foreach ($channels as $channel) {
                // 指定级别的告警是否走该通道（必填布尔，前端须全量提交）。
                $rules["severity_channels.{$severity}.{$channel}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }
}
