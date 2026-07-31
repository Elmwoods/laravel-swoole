<?php

namespace App\Http\Requests\Admin\Ops;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 告警列表查询验证。
 *
 * 对应运维中心告警列表的 GET 查询接口：所有字段均为筛选/分页参数，
 * 因此全部 nullable（不传即不按该条件过滤，使用默认分页）。
 * 各枚举/正则用于把查询参数约束在受控集合内，防止拼接出非法查询条件。
 */
class AlertIndexRequest extends FormRequest
{
    // authorize 返回 true 表示此处不做鉴权，实际访问控制由路由中间件（admin/权限）负责。
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则说明（均为可选筛选条件）：
     * - status：告警状态，in 白名单只允许 open/acknowledged/resolved 三态，防止未知状态值。
     * - severity：严重级别，in 白名单只允许 critical/warning/info。
     * - source：来源标识，正则 /^[A-Za-z0-9_-]+$/ 仅允许字母数字下划线连字符，
     *   排除空格与特殊字符（来源为机器名/服务名，属技术标识而非人名，故比 assigned_to 更严格）；max:50 限长。
     * - assigned_to：按处理人筛选，仅 string + max:120，不加白名单正则（用于宽松匹配已有指派值）。
     * - assigned：指派状态过滤，in 白名单 unassigned（仅看未指派）或 any（任意），用于快速筛选待认领告警。
     * - tag：按标签筛选，正则 /^[A-Za-z0-9_:.\-]+$/ 允许字母数字及 _ : . -（冒号常用于 key:value 形式标签）；max:60。
     * - page：页码，integer 且 min:1，避免 0 或负页码。
     * - per_page：每页条数，integer 且 min:5 max:100，限制单页返回量以保护后端性能。
     */
    public function rules(): array
    {
        return [
            // 状态过滤：仅允许 open/acknowledged/resolved 三种白名单值
            'status' => ['nullable', 'string', 'in:open,acknowledged,resolved'],
            // 级别过滤：仅允许 critical/warning/info 白名单值
            'severity' => ['nullable', 'string', 'in:critical,warning,info'],
            // 来源过滤：技术标识白名单正则（字母数字下划线连字符），最长 50
            'source' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            // 处理人过滤：宽松字符串匹配，不加正则，最长 120
            'assigned_to' => ['nullable', 'string', 'max:120'],
            // 指派状态过滤：unassigned=仅未指派 / any=任意
            'assigned' => ['nullable', 'string', 'in:unassigned,any'],
            // 标签过滤：允许 key:value 形式（含冒号点连字符），最长 60
            'tag' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_:.\\-]+$/'],
            // 页码：至少为 1
            'page' => ['nullable', 'integer', 'min:1'],
            // 每页条数：范围 5~100，防止一次拉取过多数据
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
