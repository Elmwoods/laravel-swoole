<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

/**
 * Laravel 应用日志服务。
 *
 * 运维中心「日志」板块的一个来源：把 storage/logs/laravel.log 交给统一的
 * LogFileReaderService 读取、过滤、分页。本类只负责固定日志路径 + 来源标识，
 * 具体的 tail / 关键词 / 时间范围逻辑全部复用 reader，避免各来源重复实现。
 */
class LaravelLogService
{
    /**
     * 作用：注入统一的日志文件读取服务。
     *
     * @param  LogFileReaderService  $reader  负责 tail、过滤、聚合成日志事件的底层读取器
     */
    public function __construct(
        private readonly LogFileReaderService $reader,
    ) {}

    /**
     * 获取 Laravel 应用日志。
     *
     * 作用：以 laravel.log 为文件、laravel 为来源标识，委托 reader 按查询条件返回日志。
     *
     * @param  LogQueryDTO  $dto  前端传入的查询条件（tail/full 模式、行数、关键词、时间范围、分页）
     * @return array 统一的日志查询结果（含 entries、facets、pagination 等字段）
     */
    public function latest(LogQueryDTO $dto): array
    {
        // 固定读取 storage/logs/laravel.log；来源标识传 'laravel'，reader 会据此启用 Laravel 专用的行清洗/解析逻辑。
        return $this->reader->read(
            storage_path('logs/laravel.log'),
            $dto,
            'laravel',
        );
    }
}
