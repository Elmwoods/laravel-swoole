<?php

namespace App\Http\Requests\Admin\Ops;

use App\DTO\Ops\Log\LogQueryDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ops Center 日志查询请求验证。
 *
 * 第三阶段所有日志接口统一走这个 Request，避免 Controller 里散落参数校验。
 * lines 做上限控制，防止一次读取过多日志拖慢 Octane Worker。
 */
class LogQueryRequest extends FormRequest
{
    /**
     * 当前 Ops Center 暂未接入 RBAC，先允许访问。
     * 后续接入管理员权限后，可在这里校验“日志查看”权限。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 日志查询参数规则。
     */
    public function rules(): array
    {
        return [
            'lines' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'tail' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:from'],
            'source' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'container' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ];
    }

    /**
     * 中文验证提示，便于前端直接展示。
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
     */
    public function dto(): LogQueryDTO
    {
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
            level: $this->filled('level') ? strtoupper((string) $this->string('level')) : null,
            from: $this->filled('from') ? (string) $this->string('from') : null,
            to: $this->filled('to') ? (string) $this->string('to') : null,
        );
    }
}
