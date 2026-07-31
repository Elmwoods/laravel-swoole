<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 告警筛选预设保存请求验证。
 *
 * 对应运维中心「保存一组常用告警筛选条件为预设」的接口：用户给筛选条件起个名字（name）
 * 并连同一组过滤条件（filters）保存下来，下次可一键套用。
 * filters 下各子字段刻意镜像 AlertIndexRequest 的同名规则，保证「保存的预设」与「实际列表查询」
 * 接受的取值范围一致，避免存进一个列表接口无法识别的筛选值。
 */
class AlertPresetStoreRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验预设名 + 嵌套 filters，各字段镜像 AlertIndexRequest。
     *
     * 规则说明：
     * - name：预设名称，required 必填；max:80 限长。
     * - filters：过滤条件集合，nullable + array，可空（允许保存空条件的预设）。
     * - filters.status：状态筛选，nullable；Rule::in 白名单 open/acknowledged/resolved，与列表接口一致。
     * - filters.severity：级别筛选，nullable；Rule::in 白名单 critical/warning/info，与列表接口一致。
     * - filters.source：来源筛选，nullable；正则 /^[A-Za-z0-9_-]+$/ 仅允许字母数字下划线连字符，max:50，
     *   与 AlertIndexRequest 的 source 规则保持一致。
     */
    public function rules(): array
    {
        return [
            // 预设名称：必填，最长 80
            'name' => ['required', 'string', 'max:80'],
            // 过滤条件集合：可空数组
            'filters' => ['nullable', 'array'],
            // 状态条件：白名单同列表接口
            'filters.status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved'])],
            // 级别条件：白名单同列表接口
            'filters.severity' => ['nullable', Rule::in(['critical', 'warning', 'info'])],
            // 来源条件：技术标识白名单正则，最长 50，同列表接口
            'filters.source' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
