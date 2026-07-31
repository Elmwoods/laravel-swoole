<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

/**
 * 系统日志服务。
 *
 * 系统日志只能从 config/ops.php 的白名单读取，不能让前端传任意路径。
 * 这能避免“日志查看”功能变成任意文件读取入口。
 */
class SystemLogService
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
     * 获取系统日志来源列表。
     *
     * 作用：把 config/ops.php 白名单里的系统日志来源整理成前端可选列表，
     * 并附带每个文件当前是否存在、是否可读，方便前端灰化不可用来源。
     *
     * @return array 每项为 { key, path, exists, readable } 的来源数组
     */
    public function sources(): array
    {
        // 只枚举白名单配置里的来源；这里顺带做 is_file/is_readable 探测，让前端知道哪些来源当前不可用。
        return collect(config('ops.logs.system_sources', []))
            ->map(fn (string $path, string $key): array => [
                'key' => $key,
                'path' => $path,
                'exists' => is_file($path),
                'readable' => is_readable($path),
            ])
            ->values()
            ->all();
    }

    /**
     * 读取指定系统日志来源。
     *
     * 作用：根据 DTO 指定的 source 从白名单取出对应文件路径再交给 reader；
     * 未指定时回退到白名单第一个来源。
     *
     * 为什么：source → path 的映射只走白名单 $sources，前端只能传 key 而非任意路径，
     * 从而杜绝「日志查看」被当成任意文件读取漏洞利用。
     *
     * @param  LogQueryDTO  $dto  查询条件；其 source 字段是白名单里的来源 key
     * @return array 统一的日志查询结果；来源不在白名单时返回带提示的空结果
     */
    public function latest(LogQueryDTO $dto): array
    {
        $sources = config('ops.logs.system_sources', []);
        // 未显式指定来源时回退到白名单第一个（array_key_first）。
        $source = $dto->source ?: array_key_first($sources);
        // 仅从白名单映射取路径；传入的 key 不存在则得到 null，绝不拼接前端提供的路径。
        $file = $sources[$source] ?? null;

        if (! $file) {
            return [
                'source' => $source,
                'path' => null,
                'exists' => false,
                'readable' => false,
                'message' => '系统日志来源未配置或不在白名单中',
                'lines' => [],
                'count' => 0,
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        // 来源标识加 'system:' 前缀，便于下游（导出、告警）区分系统日志与 laravel/octane 日志。
        return $this->reader->read($file, $dto, 'system:'.$source);
    }
}
