<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 后台审计日志「筛选预设保存」请求。
 *
 * 对应管理端「保存审计筛选预设」接口（POST）：给一组常用筛选条件起个名字存下来，
 * 下次一键套用。因此本请求 = 预设名 name + 一份嵌套的 filters（其内部字段逐一
 * 镜像 AdminAuditIndexRequest 的查询条件，保证「存进去的」和「能查的」口径一致）。
 */
class AdminAuditPresetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // authorize() 恒返回 true，鉴权交由路由中间件（权限点校验）负责，不在此判断。
        return true;
    }

    /**
     * 校验预设名 + 嵌套 filters，各字段镜像 AdminAuditIndexRequest。
     *
     * - name：预设名称，required（预设必须有名字才能保存/展示）。
     * - filters：整个筛选对象可空（允许保存一个「无条件」的预设），存在时必须是数组。
     * - filters.* 各子键与 AdminAuditIndexRequest 一一对应，含义相同：
     *   module/action 用同一套白名单正则（小写字母数字下划线点连字符）防止非法字符；
     *   result 同样用 Rule::in 白名单限定 success/failure；status_code 限 100~599；
     *   to 用 after_or_equal:filters.from 保证时间区间合法（注意此处指向嵌套键 filters.from）。
     *   这里没有 page/per_page —— 分页是查询运行期的参数，不属于要持久化的预设条件。
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'], // 预设必须有名字
            'filters' => ['nullable', 'array'], // 整份筛选条件可为空，存在则须为数组
            'filters.admin_user_id' => ['nullable', 'integer', 'exists:admin_users,id'], // 操作人过滤，需真实存在
            'filters.module' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'], // 模块名白名单字符集
            'filters.action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'], // 动作名白名单字符集
            'filters.result' => ['nullable', Rule::in(['success', 'failure'])], // 结果枚举白名单
            'filters.keyword' => ['nullable', 'string', 'max:120'], // 模糊搜索关键字
            'filters.status_code' => ['nullable', 'integer', 'min:100', 'max:599'], // 合法状态码区间
            'filters.from' => ['nullable', 'date'], // 时间范围起点
            'filters.to' => ['nullable', 'date', 'after_or_equal:filters.from'], // 结束不得早于嵌套键 filters.from
        ];
    }
}
