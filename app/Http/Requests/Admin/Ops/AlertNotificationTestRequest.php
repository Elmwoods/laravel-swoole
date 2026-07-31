<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 告警通知测试请求验证。
 *
 * 只允许测试受支持的通知通道，避免前端传入任意字符串影响后端逻辑。
 * 支持通道单一来源于 config('ops.alerts.channels')，与 Service 白名单同源。
 */
class AlertNotificationTestRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明：
     * - 先从配置读取「受支持的通知通道」白名单（默认 telegram、mail），作为单一事实来源，
     *   与 Service 层白名单同源，避免两处硬编码不一致。
     * - channels：待测试的通道列表，nullable + array（不传则由后端按默认通道测试）；
     *   max 上限取 max(1, count($channels))——即最多不超过已配置通道数，且至少为 1，
     *   防止 count 为 0 时生成 max:0 这种不可能满足的规则。
     * - channels.*：数组内每个通道值，须为 string 且 Rule::in($channels)——只允许出现在配置白名单里的通道，
     *   拦截前端传入任意字符串影响后端发送逻辑。
     * - message：测试消息文本，nullable 可空；max:300 限制测试文案长度。
     */
    public function rules(): array
    {
        // 支持通道白名单：来源于 config('ops.alerts.channels')，与 Service 白名单同源
        $channels = (array) config('ops.alerts.channels', ['telegram', 'mail']);

        return [
            // 通道列表：可空数组，元素数量上限为已配置通道数（至少 1，避免 max:0）
            'channels' => ['nullable', 'array', 'max:'.max(1, count($channels))],
            // 单个通道：必须是配置白名单内的通道值
            'channels.*' => ['string', Rule::in($channels)],
            // 测试消息：可空字符串，最长 300
            'message' => ['nullable', 'string', 'max:300'],
        ];
    }
}
