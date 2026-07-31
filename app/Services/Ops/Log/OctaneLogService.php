<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

/**
 * Octane / Swoole 运行时日志服务。
 *
 * 运维中心「日志」板块的一个来源：读取 storage/logs/octane.log（Octane 常驻
 * worker 的标准输出/错误）。与 LaravelLogService 结构一致，只是文件路径和来源
 * 标识不同，实际读取逻辑复用 LogFileReaderService。
 */
class OctaneLogService
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
     * 获取 Octane / Swoole 日志。
     *
     * 作用：以 octane.log 为文件、octane 为来源标识，委托 reader 按查询条件返回日志。
     *
     * @param  LogQueryDTO  $dto  前端传入的查询条件（tail/full 模式、行数、关键词、时间范围、分页）
     * @return array 统一的日志查询结果
     */
    public function latest(LogQueryDTO $dto): array
    {
        // 固定读取 storage/logs/octane.log；来源标识 'octane' 走通用（非 laravel）解析分支。
        return $this->reader->read(
            storage_path('logs/octane.log'),
            $dto,
            'octane',
        );
    }
}
