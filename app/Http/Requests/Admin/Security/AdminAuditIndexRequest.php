<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台安全审计日志「列表查询」请求。
 *
 * 对应管理端「审计日志」列表接口（GET），校验分页与筛选条件。
 * 所有字段均为可选筛选项：调用方不传即返回全量（受分页限制），传了才叠加过滤。
 */
class AdminAuditIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，表示鉴权不在此处做，而是交由路由中间件（权限点校验）负责。
        return true;
    }

    /**
     * 审计列表查询参数校验规则。
     *
     * 由于是「查询」而非「写入」，每个字段都以 nullable 开头（缺省即不过滤）：
     * - admin_user_id：按操作人筛选，必须是 admin_users 表里真实存在的 id（exists 防止无效查询）。
     * - module / action：模块名、动作名。regex 限定为「小写字母、数字、下划线、点、连字符」，
     *   与系统内部埋点命名规则一致，白名单式字符集可拦截注入类特殊字符。
     * - result：执行结果，Rule::in 白名单只允许 success / failure 两种枚举，避免任意取值。
     * - keyword：模糊搜索关键字，仅限长度，不限字符。
     * - status_code：HTTP 状态码，限定 100~599 的合法码段。
     * - from / to：时间范围；to 用 after_or_equal:from 保证结束时间不早于开始时间。
     * - page / per_page：分页参数；per_page 限定 5~100，防止一次拉取过多数据拖垮查询。
     */
    public function rules(): array
    {
        return [
            'admin_user_id' => ['nullable', 'integer', 'exists:admin_users,id'], // 操作人过滤，必须存在于 admin_users
            'module' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'], // 模块名白名单字符集
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'], // 动作名白名单字符集
            'result' => ['nullable', Rule::in(['success', 'failure'])], // 结果枚举，仅成功/失败
            'keyword' => ['nullable', 'string', 'max:120'], // 模糊搜索关键字
            'status_code' => ['nullable', 'integer', 'min:100', 'max:599'], // 合法 HTTP 状态码区间
            'from' => ['nullable', 'date'], // 时间范围起点
            'to' => ['nullable', 'date', 'after_or_equal:from'], // 结束时间不得早于起点
            'page' => ['nullable', 'integer', 'min:1'], // 页码从 1 起
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'], // 每页条数上限，防止过量查询
        ];
    }
}
