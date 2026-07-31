<?php

namespace App\DTO\Ops\Log;

/**
 * 日志查询 DTO。
 *
 * 将日志查询接口的各类过滤/分页参数打包成不可变对象，在 Controller 与
 * 日志服务之间传递，避免到处透传零散的原始请求参数。
 */
class LogQueryDTO
{
    public function __construct(
        public int $lines = 200,          // tail 模式下拉取的末尾行数
        public ?string $keyword = null,   // 关键词过滤（可空=不过滤）
        public ?string $source = null,    // 日志来源过滤（如某个应用/服务）
        public ?string $container = null, // Docker 容器过滤（容器日志时使用）
        public int $page = 1,             // 分页页码（paginate 模式）
        public int $perPage = 20,         // 每页条数（paginate 模式）
        public ?string $level = null,     // 日志级别过滤（error/warning/info 等）
        public ?string $from = null,      // 起始时间范围（可空）
        public ?string $to = null,        // 结束时间范围（可空）
        public string $mode = 'tail',     // 查询模式：tail（尾部实时）/ paginate（分页检索）
        public bool $forExport = false,   // 是否为导出场景（导出通常放宽限制、不分页截断）
    ) {}
}
