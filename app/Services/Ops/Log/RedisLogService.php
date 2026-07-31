<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis 慢日志服务。
 *
 * 运维中心「日志」板块的一个来源：通过 Redis 的 SLOWLOG 命令拉取慢查询记录，
 * 归一成前端可直接渲染的结构，并支持关键词/时间范围过滤与分页。所有 Redis
 * 调用都包在 try/catch 里，Redis 不可用时返回 available=false 的降级结果，
 * 不影响运维中心其他面板。
 */
class RedisLogService
{
    /**
     * 获取 Redis SlowLog。
     *
     * Redis SLOWLOG GET 返回数组结构，不同扩展的返回键可能不同，
     * 这里统一格式化为前端可直接渲染的结构。
     *
     * 作用：拉取慢日志 → 归一字段 → 关键词/时间过滤 → 分页，返回统一结果。
     *
     * @param  int|LogQueryDTO  $query  查询条件；传 int 时按行数快捷构造 DTO
     * @return array 统一结果；Redis 不可用时 available=false
     */
    public function slowLogs(int|LogQueryDTO $query = 100): array
    {
        // 兼容旧调用：直接传条数（int）时构造 DTO，并把每页上限夹到 100。
        $dto = $query instanceof LogQueryDTO ? $query : new LogQueryDTO(lines: $query, perPage: min($query, 100));

        try {
            $limit = $this->slowLogLimit($dto);
            // SLOWLOG GET <limit>：返回若干条最近的慢查询记录。
            $rows = Redis::command('SLOWLOG', ['GET', $limit]);
        } catch (Throwable $e) {
            // Redis 不可用（未连接/命令被禁用等）：返回降级结果，让前端展示「不可用」而非报错。
            return [
                'source' => 'redis',
                'available' => false,
                'message' => 'Redis 慢日志不可用',
                'entries' => [],
                'count' => 0,
                'pagination' => $this->pagination([], $dto),
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        // 归一每条慢日志：不同 Redis 扩展可能返回位置索引数组（[0..5]）或关联键，故用 位置 ?? 关联键 ?? 默认 兼容两种。
        $entries = collect($rows ?? [])
            ->map(function (array $row): array {
                // 命令部分：位置 3 或键 'command'，可能是数组（命令 + 参数），下面再拼成字符串。
                $command = $row[3] ?? $row['command'] ?? [];

                return [
                    'id' => (int) ($row[0] ?? $row['id'] ?? 0),
                    // SLOWLOG 时间戳是 Unix 秒，转成可读时间。
                    'occurred_at' => date('Y-m-d H:i:s', (int) ($row[1] ?? $row['timestamp'] ?? time())),
                    // 耗时原始单位是微秒，/1000 转毫秒并保留 3 位小数。
                    'duration_ms' => round(((int) ($row[2] ?? $row['duration'] ?? 0)) / 1000, 3),
                    // 命令为数组时用空格拼成完整命令串，否则原样转字符串。
                    'command' => is_array($command) ? implode(' ', array_map('strval', $command)) : (string) $command,
                    'client' => (string) ($row[4] ?? $row['client'] ?? ''),
                    'client_name' => (string) ($row[5] ?? $row['client_name'] ?? ''),
                ];
            })
            ->values()
            ->all();

        // 关键词过滤：不区分大小写地在 命令/客户端地址/客户端名 中做包含匹配。
        if ($dto->keyword) {
            $keyword = mb_strtolower($dto->keyword);
            $entries = array_values(array_filter($entries, function (array $entry) use ($keyword): bool {
                return str_contains(mb_strtolower($entry['command']), $keyword)
                    || str_contains(mb_strtolower($entry['client']), $keyword)
                    || str_contains(mb_strtolower($entry['client_name']), $keyword);
            }));
        }

        // 指定了起止时间才做时间范围过滤。
        if ($dto->from || $dto->to) {
            $entries = $this->filterByTimeRange($entries, $dto);
        }

        // 导出模式返回全部匹配、不分页；普通模式按分页参数切片。
        $pagination = $dto->forExport
            ? $this->exportPagination($entries)
            : $this->pagination($entries, $dto);
        $pagedEntries = $dto->forExport
            ? $entries
            : array_slice(
                $entries,
                ($pagination['current_page'] - 1) * $pagination['per_page'],
                $pagination['per_page'],
            );

        return [
            'source' => 'redis',
            'available' => true,
            'mode' => $dto->mode,
            'entries' => array_values($pagedEntries),
            'count' => count($entries),
            'pagination' => $pagination,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 计算本次 SLOWLOG GET 的取数上限。
     *
     * 作用：tail 模式按请求行数取（夹到 [10,1000]）；full 模式先查慢日志总长度再取全量。
     *
     * 为什么：full 模式想拿到当前所有慢日志，但 SLOWLOG 缓冲区可能很大，
     * 所以用 SLOWLOG LEN 得到实际条数并再夹到 [10,10000] 上限，防止一次取回过多。
     *
     * @param  LogQueryDTO  $dto  查询条件（决定 tail/full 与行数）
     * @return int 传给 SLOWLOG GET 的条数上限
     */
    private function slowLogLimit(LogQueryDTO $dto): int
    {
        // 非 full：只取请求的行数，夹到 [10,1000]。
        if ($dto->mode !== 'full') {
            return max(10, min($dto->lines, 1000));
        }

        try {
            // full：先问 Redis 当前慢日志条数，再据此决定取多少。
            $length = (int) Redis::command('SLOWLOG', ['LEN']);
        } catch (Throwable) {
            // LEN 失败时给一个保守默认值。
            $length = 1000;
        }

        // full 模式上限放宽到 10000，仍夹住以免极端情况下取回过多。
        return max(10, min($length, 10000));
    }

    /**
     * 按 occurred_at 做时间范围过滤。
     *
     * 作用：只保留发生时间落在 [from, to] 内的慢日志；时间无法解析的记录被剔除。
     *
     * @param  array  $entries  归一后的慢日志数组
     * @param  LogQueryDTO  $dto  含 from/to 的查询条件
     * @return array 过滤并重建索引后的数组
     */
    private function filterByTimeRange(array $entries, LogQueryDTO $dto): array
    {
        // from/to 为空表示该端不设限；strtotime 解析成 Unix 时间戳做比较。
        $from = $dto->from ? strtotime($dto->from) : null;
        $to = $dto->to ? strtotime($dto->to) : null;

        return array_values(array_filter($entries, function (array $entry) use ($from, $to): bool {
            $time = strtotime((string) ($entry['occurred_at'] ?? ''));

            // 无法解析时间的记录在时间筛选下一律不展示。
            if ($time === false) {
                return false;
            }

            if ($from !== null && $time < $from) {
                return false;
            }

            if ($to !== null && $time > $to) {
                return false;
            }

            return true;
        }));
    }

    /**
     * 生成慢日志分页信息。
     *
     * 作用：根据总条数与请求分页参数算出当前页/每页/总页数等。
     *
     * 为什么：perPage 夹到 [5,100]、page 夹到 [1,lastPage]，防止前端传入越界值导致空页或过大查询。
     *
     * @param  array  $entries  过滤后的全部条目
     * @param  LogQueryDTO  $dto  含 page/perPage 的查询条件
     * @return array 分页信息
     */
    private function pagination(array $entries, LogQueryDTO $dto): array
    {
        $total = count($entries);
        $perPage = max(5, min($dto->perPage, 100));
        $lastPage = max(1, (int) ceil($total / $perPage));
        // 请求页夹到 [1, lastPage]，避免越界。
        $currentPage = max(1, min($dto->page, $lastPage));

        return [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $currentPage < $lastPage,
        ];
    }

    /**
     * 导出模式的分页信息（实际不分页）。
     *
     * 作用：把所有条目放在一页，用于 CSV 导出等需要全量数据的场景。
     *
     * @param  array  $entries  过滤后的全部条目
     * @return array 单页覆盖全部条目的分页信息
     */
    private function exportPagination(array $entries): array
    {
        $total = count($entries);

        return [
            'current_page' => 1,
            'per_page' => max($total, 1),
            'total' => $total,
            'last_page' => 1,
            'has_more' => false,
        ];
    }
}
