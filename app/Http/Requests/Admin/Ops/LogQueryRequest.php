<?php

namespace App\Http\Requests\Admin\Ops;

use App\DTO\Ops\Log\LogQueryDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ops Center 日志查询请求验证。
 *
 * 第三阶段所有日志接口统一走这个 Request，避免 Controller 里散落参数校验。
 * tail 模式继续做行数上限控制，full 模式通过分页展示完整日志来源。
 */
class LogQueryRequest extends FormRequest
{
    /**
     * RBAC 已由 admin.auth 与 admin.permission 中间件统一处理。
     *
     * 返回 true 表示本 Request 不在此处做鉴权，授权完全交给路由中间件（admin.auth / admin.permission）。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 日志查询参数规则。
     *
     * 各字段全部 nullable，意味着这些参数都是可选的查询过滤条件，缺省时由 dto() 提供默认值。
     * - lines / tail：日志读取行数，整数且限定在 10~1000，避免一次拉取过多日志拖垮服务；
     *   两者语义相同（tail 优先），都做上限控制防止 OOM。
     * - mode：仅允许 tail 或 full 两个白名单值（in 约束），tail 为末尾若干行、full 为分页浏览完整日志。
     * - page / per_page：分页参数，page 最小 1、最大 1000，per_page 最小 5、最大 100，
     *   限定每页条数上限防止单页返回过大。
     * - keyword：关键词过滤，长度上限 120，防止超长字符串影响检索性能。
     * - level：日志级别，regex /^[A-Za-z]+$/ 表示只允许纯英文字母（如 INFO、ERROR），
     *   拒绝数字与特殊符号，避免非法级别注入；后续 dto() 会统一转大写。
     * - from / to：时间范围，date_format:Y-m-d H:i:s 强制精确到秒的标准格式；
     *   to 的 after_or_equal:from 保证结束时间不早于开始时间，避免无意义区间。
     * - source：系统日志来源，regex /^[A-Za-z0-9_.:-]+$/ 只允许字母数字与 _ . : - ，
     *   拒绝空格及其它特殊字符，防止路径/命令注入。
     * - container：Docker 容器标识，字符集同 source，长度上限 128（容器 ID/名称可能较长）。
     */
    public function rules(): array
    {
        return [
            // 日志行数：可空整数，10~1000 上限防止一次读取过多日志
            'lines' => ['nullable', 'integer', 'min:10', 'max:1000'],
            // tail 行数：与 lines 同义，dto() 中 tail 优先于 lines
            'tail' => ['nullable', 'integer', 'min:10', 'max:1000'],
            // 查看模式：白名单仅允许 tail（末尾行）或 full（分页完整日志）
            'mode' => ['nullable', 'string', 'in:tail,full'],
            // 分页页码：最小 1，最大 1000
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // 每页条数：5~100，限制单页返回体量
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            // 关键词：长度上限 120，避免超长检索串
            'keyword' => ['nullable', 'string', 'max:120'],
            // 日志级别：正则仅允许纯字母（INFO/ERROR 等），拒绝数字与符号
            'level' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]+$/'],
            // 起始时间：强制 Y-m-d H:i:s 精确到秒
            'from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            // 结束时间：格式同上，且必须晚于或等于 from，保证区间有效
            'to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:from'],
            // 日志来源：正则限定字母数字与 _ . : - ，防止注入
            'source' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            // 容器标识：字符集同 source，长度上限 128
            'container' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ];
    }

    /**
     * 中文验证提示，便于前端直接展示。
     *
     * 覆盖各字段的 integer / min / max / in / regex / date_format / after_or_equal 校验失败文案，
     * 使后台界面无需再翻译默认英文错误。
     */
    public function messages(): array
    {
        return [
            'lines.integer' => '日志行数必须是整数。',
            'lines.min' => '日志行数不能小于 10。',
            'lines.max' => '日志行数不能超过 1000。',
            'tail.integer' => 'tail 行数必须是整数。',
            'tail.min' => 'tail 行数不能小于 10。',
            'tail.max' => 'tail 行数不能超过 1000。',
            'mode.in' => '日志查看模式不合法。',
            'page.integer' => '分页页码必须是整数。',
            'page.min' => '分页页码不能小于 1。',
            'per_page.integer' => '每页条数必须是整数。',
            'per_page.min' => '每页条数不能小于 5。',
            'per_page.max' => '每页条数不能超过 100。',
            'keyword.max' => '日志关键词不能超过 120 个字符。',
            'level.regex' => '日志级别格式不合法。',
            'from.date_format' => '日志开始时间格式必须是 YYYY-MM-DD HH:mm:ss。',
            'to.date_format' => '日志结束时间格式必须是 YYYY-MM-DD HH:mm:ss。',
            'to.after_or_equal' => '日志结束时间不能早于开始时间。',
            'source.regex' => '系统日志来源格式不合法。',
            'container.regex' => 'Docker 容器 ID 格式不合法。',
        ];
    }

    /**
     * 转换为日志查询 DTO。
     *
     * 将已验证的请求参数整理成不可变的 LogQueryDTO 供服务层使用：
     * - tail 优先于 lines：若显式传了 tail 则用它，否则用 lines（默认 200 行）。
     * - 其余可空字段仅在 filled 时才赋值，未填时统一置 null / 使用默认，交由下游按需处理。
     * - level 在此统一 strtoupper 转大写，保证与日志中的级别标识匹配。
     */
    public function dto(): LogQueryDTO
    {
        // tail 存在则以 tail 为准，否则回退到 lines（缺省 200 行）
        $lines = $this->filled('tail')
            ? (int) $this->integer('tail')
            : (int) $this->integer('lines', 200);

        return new LogQueryDTO(
            lines: $lines,
            keyword: $this->filled('keyword') ? (string) $this->string('keyword') : null,
            source: $this->filled('source') ? (string) $this->string('source') : null,
            container: $this->filled('container') ? (string) $this->string('container') : null,
            page: (int) $this->integer('page', 1),
            perPage: (int) $this->integer('per_page', 20),
            // level 统一转大写以匹配日志中的级别字面量
            level: $this->filled('level') ? strtoupper((string) $this->string('level')) : null,
            from: $this->filled('from') ? (string) $this->string('from') : null,
            to: $this->filled('to') ? (string) $this->string('to') : null,
            // mode 缺省为 tail（末尾行模式）
            mode: $this->filled('mode') ? (string) $this->string('mode') : 'tail',
        );
    }
}
