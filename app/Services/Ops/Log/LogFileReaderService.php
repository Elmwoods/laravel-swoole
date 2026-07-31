<?php

namespace App\Services\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;

/**
 * 日志文件读取服务。
 *
 * 统一处理 tail、关键词过滤和返回结构，避免 Laravel / Octane / System
 * 日志服务重复读取文件逻辑。这里不使用 shell tail，避免命令拼接风险。
 */
class LogFileReaderService
{
    /**
     * 根据查询模式读取日志。
     *
     * 作用：full 模式读整文件、其它模式只读尾部若干行，是各来源的统一入口。
     *
     * @param  string  $file  日志文件绝对路径
     * @param  LogQueryDTO  $dto  查询条件（模式、行数、关键词、时间、分页）
     * @param  string  $source  来源标识（laravel/octane/system:xxx/docker:xxx）
     * @return array 统一日志查询结果
     */
    public function read(string $file, LogQueryDTO $dto, string $source): array
    {
        return $dto->mode === 'full'
            ? $this->full($file, $dto, $source)
            : $this->tail($file, $dto, $source);
    }

    /**
     * 读取文件末尾日志。
     *
     * 作用：只读文件最后 N 行再交给 fromLines 聚合，适合日常快速查看最新日志。
     *
     * @param  string  $file  日志文件绝对路径
     * @param  LogQueryDTO  $dto  查询条件
     * @param  string  $source  来源标识
     * @return array 统一结果；文件不存在或不可读时返回带 exists/readable 标记的空结果
     */
    public function tail(string $file, LogQueryDTO $dto, string $source): array
    {
        // 读前先探测存在性与可读性，把结果如实回传前端（区分“没有文件”与“无权限”）。
        if (! is_file($file) || ! is_readable($file)) {
            return $this->emptyResult($source, '日志文件不存在或不可读', is_file($file), is_readable($file));
        }

        // 行数先归一到 [10,1000] 再从尾部读取。
        $lines = $this->readLastLines($file, $this->normalizeLines($dto->lines));
        $result = $this->fromLines($lines, $dto, $source);

        return [
            ...$result,
            'path' => $file,
            'exists' => true,
            'readable' => true,
        ];
    }

    /**
     * 读取完整文件日志并分页返回。
     *
     * 这里按行迭代读取，避免使用 file() 一次性读取整个文件。
     *
     * 作用：读取整个日志文件的全部行，再交给 fromLines 做过滤与分页。
     *
     * @param  string  $file  日志文件绝对路径
     * @param  LogQueryDTO  $dto  查询条件
     * @param  string  $source  来源标识
     * @return array 统一结果（额外带 mode='full'）
     */
    public function full(string $file, LogQueryDTO $dto, string $source): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return $this->emptyResult($source, '日志文件不存在或不可读', is_file($file), is_readable($file));
        }

        $result = $this->fromLines($this->readAllLines($file), $dto, $source);

        return [
            ...$result,
            'path' => $file,
            'exists' => true,
            'readable' => true,
            'mode' => 'full',
        ];
    }

    /**
     * 从已读取的日志行生成统一查询结果。
     *
     * 作用：原始行 → 聚合成事件 → 关键词/时间/级别过滤 → 计算钻取维度 → 倒序排序 → 分页。
     * 这是所有来源（文件、docker）最终汇聚的组装点。
     *
     * 为什么 facets 在级别过滤之前计算：级别分布要反映过滤前的全貌，
     * 否则按 ERROR 过滤后统计里就只剩 ERROR，级别切换失去意义。
     *
     * @param  array  $lines  原始日志行
     * @param  LogQueryDTO  $dto  查询条件
     * @param  string  $source  来源标识
     * @return array 含 lines/entries/facets/pagination 等字段的统一结果
     */
    public function fromLines(array $lines, LogQueryDTO $dto, string $source): array
    {
        // 先把逐行文本聚合成“日志事件”（一条日志 + 其后续堆栈行）。
        $entries = $this->toEntries($lines, $source);

        // 关键词过滤：不区分大小写在事件完整内容里做包含匹配。
        if ($dto->keyword) {
            $keyword = mb_strtolower($dto->keyword);
            $entries = array_values(array_filter($entries, function (array $entry) use ($keyword): bool {
                return str_contains(mb_strtolower($entry['content']), $keyword);
            }));
        }

        if ($dto->from || $dto->to) {
            $entries = $this->filterByTimeRange($entries, $dto);
        }

        // facets 在级别过滤之前算：级别分布/时间线要展示当前（关键词+时间过滤后）的全级别全貌。
        $facets = $this->facets($entries, $source);

        // 级别过滤放在 facets 之后：缺省级别按 INFO 处理。
        if ($dto->level) {
            $level = mb_strtoupper($dto->level);
            $entries = array_values(array_filter($entries, function (array $entry) use ($level): bool {
                return mb_strtoupper((string) ($entry['level'] ?? 'INFO')) === $level;
            }));
        }

        // 有任何过滤条件时，重算 $lines（供 count 用），使其与过滤后的事件保持一致。
        if ($dto->keyword || $dto->level || $dto->from || $dto->to) {
            $lines = collect($entries)
                ->flatMap(fn (array $entry): array => $entry['lines'])
                ->values()
                ->all();
        }

        // 按时间倒序（最新在前）。
        $entries = $this->sortLatestFirst($entries);

        // 导出模式全量一页，普通模式按分页参数。
        $pagination = $dto->forExport
            ? $this->exportPagination($entries)
            : $this->pagination($entries, $dto);
        // 导出取全部，普通模式按 (页-1)*每页 偏移切片当前页。
        $pagedEntries = $dto->forExport
            ? $entries
            : array_slice(
                $entries,
                ($pagination['current_page'] - 1) * $pagination['per_page'],
                $pagination['per_page'],
            );
        // 仅对当前页事件生成预览（前若干行 + 截断标记），避免对全量数据做无用计算。
        $pagedEntries = array_map(
            fn (array $entry): array => $this->withPreview($entry),
            $pagedEntries,
        );
        $pagedLines = collect($pagedEntries)
            ->flatMap(fn (array $entry): array => $entry['lines'])
            ->values()
            ->all();

        return [
            'source' => $source,
            'path' => null,
            'exists' => true,
            'readable' => true,
            'lines' => $pagedLines,
            'entries' => array_values($pagedEntries),
            'facets' => $facets,
            'count' => count($lines),
            'entry_count' => count($entries),
            'pagination' => $pagination,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 按日志时间筛选；没有可解析时间的日志在时间筛选时不展示。
     *
     * 作用：只保留时间落在 [from, to] 内的事件。
     *
     * @param  array  $entries  聚合后的日志事件
     * @param  LogQueryDTO  $dto  含 from/to 的查询条件
     * @return array 过滤并重建索引后的事件数组
     */
    private function filterByTimeRange(array $entries, LogQueryDTO $dto): array
    {
        $from = $dto->from ? strtotime($dto->from) : null;
        $to = $dto->to ? strtotime($dto->to) : null;

        return array_values(array_filter($entries, function (array $entry) use ($from, $to): bool {
            $time = isset($entry['time']) ? strtotime((string) $entry['time']) : false;

            // 无时间戳的事件（如无头堆栈）在时间筛选下一律剔除。
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
     * 读取文件最后 N 行。
     *
     * 运维日志可能很大，所以从文件尾部按块读取，而不是 file() 全量载入。
     *
     * 作用：从文件末尾向前按 8KB 块读取，直到凑够 N 行或读到文件头。
     *
     * 为什么这样实现：日志文件可能上百 MB，tail 只需最后几百行，
     * 用 fseek + 反向分块读，内存占用与要取的行数相关，而非文件大小。
     *
     * @param  string  $file  日志文件绝对路径
     * @param  int  $lineCount  需要的行数
     * @return array 最后 $lineCount 行（不足则全部）
     */
    private function readLastLines(string $file, int $lineCount): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        $buffer = '';
        $chunkSize = 8192;   // 每次向前读取的块大小
        $position = -1;      // 相对文件尾的偏移游标（负数）
        $linesFound = 0;

        // 先定位到文件末尾并取得总大小，作为反向读取的基准。
        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);

        // 从尾部向前读，直到读到文件头或已凑够（略多于）目标行数。
        while ($fileSize + $position >= 0 && $linesFound <= $lineCount) {
            // 计算本块的起始位置（不越过文件头 0）与实际读取长度。
            $seek = max($fileSize + $position - $chunkSize + 1, 0);
            $readSize = $fileSize + $position - $seek + 1;

            fseek($handle, $seek);
            $chunk = fread($handle, $readSize);

            if ($chunk === false) {
                break;
            }

            // 新块拼在缓冲区前面（因为是从后往前读），再数一遍已有的换行数。
            $buffer = $chunk.$buffer;
            $linesFound = substr_count($buffer, PHP_EOL);
            $position -= $chunkSize;
        }

        fclose($handle);

        // 兼容 \r\n / \r / \n 三种换行；trim 去掉首尾空白避免产生空行。
        $lines = preg_split('/\r\n|\r|\n/', trim($buffer));

        if (! is_array($lines)) {
            return [];
        }

        // 缓冲区可能多读了几行，最后只取末尾 N 行。
        return array_slice($lines, -$lineCount);
    }

    /**
     * 按行迭代读取完整日志文件。
     *
     * 作用：用 fgets 逐行读入，避免 file() 一次性把整个文件塞进内存。
     *
     * @param  string  $file  日志文件绝对路径
     * @return array 去掉行尾换行后的所有行
     */
    private function readAllLines(string $file): array
    {
        $lines = [];
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        // 逐行读取，去掉行尾 \r\n。
        while (($line = fgets($handle)) !== false) {
            $lines[] = rtrim($line, "\r\n");
        }

        fclose($handle);

        return $lines;
    }

    /**
     * 限制日志行数范围。
     *
     * 作用：把请求行数夹到 [10,1000]，防止过小无意义或过大拖垮读取。
     *
     * @param  int  $lines  请求行数
     * @return int 夹取后的行数
     */
    private function normalizeLines(int $lines): int
    {
        return max(10, min($lines, 1000));
    }

    /**
     * 生成日志事件分页信息。
     *
     * 作用：由事件总数与请求分页参数算出当前页/每页/总页数。
     *
     * 为什么夹取：perPage 限 [5,100]、page 限 [1,lastPage]，防止前端越界参数。
     *
     * @param  array  $entries  过滤后的全部事件
     * @param  LogQueryDTO  $dto  含 page/perPage 的查询条件
     * @return array 分页信息
     */
    private function pagination(array $entries, LogQueryDTO $dto): array
    {
        $total = count($entries);
        $perPage = max(5, min($dto->perPage, 100));
        $lastPage = max(1, (int) ceil($total / $perPage));
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
     * 按日志时间倒序排列；同一时间保持文件内顺序，无时间日志排在最后。
     *
     * 作用：最新日志在前展示；相同时间戳保持原始文件顺序，无时间戳的排到末尾。
     *
     * 为什么先包一层 index：usort 不保证稳定排序，附带原始下标做兜底比较，
     * 确保同时间戳事件的相对顺序稳定（等同稳定排序）。
     *
     * @param  array  $entries  待排序事件
     * @return array 排序后的事件
     */
    private function sortLatestFirst(array $entries): array
    {
        // 预解析时间戳并记住原始下标，避免排序回调里反复 strtotime。
        $indexedEntries = array_map(
            fn (array $entry, int $index): array => [
                'entry' => $entry,
                'index' => $index,
                'timestamp' => isset($entry['time']) ? strtotime((string) $entry['time']) : false,
            ],
            $entries,
            array_keys($entries),
        );

        usort($indexedEntries, function (array $left, array $right): int {
            $leftTime = $left['timestamp'];
            $rightTime = $right['timestamp'];
            $leftHasTime = $leftTime !== false;
            $rightHasTime = $rightTime !== false;

            // 两边都有时间且不等：按时间倒序（大的在前）。
            if ($leftHasTime && $rightHasTime && $leftTime !== $rightTime) {
                return $rightTime <=> $leftTime;
            }

            // 一有一无：有时间的排前面。
            if ($leftHasTime !== $rightHasTime) {
                return $leftHasTime ? -1 : 1;
            }

            // 时间相同或都无时间：按原始下标保持稳定顺序。
            return $left['index'] <=> $right['index'];
        });

        // 剥掉排序辅助字段，只留回事件本体。
        return array_map(fn (array $item): array => $item['entry'], $indexedEntries);
    }

    /**
     * 导出模式不分页，返回完整匹配结果。
     *
     * 作用：把全部事件放在一页，供 CSV 导出取全量。
     *
     * @param  array  $entries  过滤后的全部事件
     * @return array 单页覆盖全部的分页信息
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

    /**
     * 将原始日志行聚合成日志事件。
     *
     * Laravel 日志通常以 [YYYY-mm-dd HH:ii:ss] 开头，异常堆栈会跟在下一行。
     * 这里按时间戳切分，前端就能按“每条日志”展示，而不是把堆栈混成一大片文本。
     *
     * 作用：把逐行文本折叠成“日志事件”——识别出带时间戳的首行开一条新事件，
     * 后续不带头的行（堆栈、多行消息）并入上一条事件。
     *
     * @param  array  $lines  原始（可能已被 normalizeLine 前的）日志行
     * @param  string  $source  来源标识，决定清洗与解析策略
     * @return array 事件数组，每项含 time/level/summary/content/lines
     */
    private function toEntries(array $lines, string $source): array
    {
        $entries = [];

        foreach ($lines as $line) {
            // 先清洗单行（剥离历史数组转储、过滤自引用调试等）；返回 null 表示该行丢弃。
            $line = $this->normalizeLine($line, $source);

            if ($line === null) {
                continue;
            }

            // 尝试把该行当作“日志首行”解析出时间/级别/摘要。
            $parsed = $this->parseLineHeader($line, $source);

            if ($parsed !== null) {
                // 命中首行 → 开一条新事件。
                $entries[] = [
                    'time' => $parsed['time'],
                    'level' => $parsed['level'],
                    'summary' => $parsed['summary'],
                    'content' => $line,
                    'lines' => [$line],
                ];

                continue;
            }

            // 还没有任何事件、却遇到不带头的行：
            if ($entries === []) {
                // Laravel 日志开头的孤立堆栈行没有归属，直接丢弃，避免生成无头假事件。
                if ($source === 'laravel') {
                    continue;
                }

                // 其它来源（如无标准头的系统日志）则把该行本身当成一条 INFO 事件。
                $entries[] = [
                    'time' => null,
                    'level' => 'INFO',
                    'summary' => mb_strimwidth($line, 0, 160, '...'),
                    'content' => $line,
                    'lines' => [$line],
                ];

                continue;
            }

            // 普通续行：并入上一条事件的 lines，并同步刷新 content。
            $lastIndex = count($entries) - 1;
            $entries[$lastIndex]['lines'][] = $line;
            $entries[$lastIndex]['content'] = implode(PHP_EOL, $entries[$lastIndex]['lines']);
        }

        return $entries;
    }

    /**
     * 清洗原始日志行。
     *
     * Laravel 日志里曾经写入过“日志数组转储”，实际响应会出现：
     * `147 => '[2026-07-06 03:09:45] local.ERROR: ...` 和大量 `',` 垃圾行。
     * 这里先剥离数组下标、忽略纯引号碎片，再从行内提取真实 Laravel 时间戳。
     *
     * 作用：对 laravel 来源的单行做清洗，返回真实日志文本；纯垃圾行返回 null 丢弃。
     *
     * @param  string  $line  原始行
     * @param  string  $source  来源标识；非 laravel 直接原样返回
     * @return string|null 清洗后的行；应丢弃时为 null
     */
    private function normalizeLine(string $line, string $source): ?string
    {
        $line = trim($line);
        $fromArrayDump = false;   // 标记本行是否从“数组转储”里剥出来的

        if ($line === '') {
            return null;
        }

        // 只有 laravel 来源存在历史数组转储污染，其它来源不做清洗。
        if ($source !== 'laravel') {
            return $line;
        }

        // 丢弃数组转储残留的纯碎片行：只有反斜杠/引号/逗号、或单独的 ) 与 array ( 。
        if (preg_match('/^[\\\\\']*,?$/', $line) || $line === ')' || $line === 'array (') {
            return null;
        }

        // 形如 `147 => '实际内容` 的数组元素：剥掉下标与起始引号，只留 value。
        if (preg_match('/^\d+\s*=>\s*\'?(?<value>.*)$/', $line, $matches)) {
            $fromArrayDump = true;
            $line = $matches['value'];
        }

        $line = trim($line);
        // 去掉尾部收尾引号（可能带逗号）。
        $line = preg_replace('/\',?$/', '', $line) ?? $line;
        // 还原被多重转义的反斜杠（转储会把 \ 变成 \\ 甚至 \\\\）。
        $line = str_replace(['\\\\\\\\', '\\\\'], '\\', $line);

        // 从行内提取真正的 Laravel 日志片段：[时间] env.LEVEL: 消息。
        if (preg_match('/(?<log>\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+[A-Za-z0-9_-]+\.[A-Za-z]+:\s*.*)$/', $line, $matches)) {
            $line = $matches['log'];

            // 命中的若是“日志接口自引用”DEBUG 记录则丢弃。
            return $this->isLaravelSelfReferenceDebug($line) ? null : $line;
        }

        // 保留合法的堆栈续行：[stacktrace]、#N 帧、以及 JSON 上下文收尾 "} 。
        if (preg_match('/^\[stacktrace\]$/', $line) || preg_match('/^#\d+\s+/', $line) || str_starts_with($line, '"}')) {
            return $line;
        }

        // 来自数组转储、但又不是可识别日志/堆栈的行：视为垃圾丢弃。
        if ($fromArrayDump) {
            return null;
        }

        // 非转储来源的自引用调试行也丢弃。
        if ($this->isLaravelSelfReferenceDebug($line)) {
            return null;
        }

        return $line;
    }

    /**
     * 过滤历史调试污染。
     *
     * 之前排查日志接口时，日志文件路径被写入到了 laravel.log。
     * 这类 DEBUG 记录不属于真实业务日志，展示出来会干扰分页和告警判断。
     *
     * 作用：识别以 storage/logs/laravel.log 结尾的 DEBUG 记录（日志读取自身产生的噪声）。
     *
     * @param  string  $line  待判定的行
     * @return bool 是自引用调试记录则 true
     */
    private function isLaravelSelfReferenceDebug(string $line): bool
    {
        // 匹配 `] env.DEBUG: ... storage/logs/laravel.log`（大小写不敏感）。
        return (bool) preg_match(
            '/\]\s+[A-Za-z0-9_-]+\.DEBUG:\s+.*storage\/logs\/laravel\.log\s*$/i',
            $line,
        );
    }

    /**
     * 为前端日志块生成预览信息。
     *
     * 长异常堆栈默认只展示前几行，点击后再显示完整内容。
     *
     * 作用：给事件加上 line_count、preview_lines（前 8 行）与 truncated 标记。
     *
     * @param  array  $entry  单条事件
     * @return array 附加预览字段后的事件
     */
    private function withPreview(array $entry): array
    {
        $previewLineLimit = 8;   // 预览最多展示 8 行
        $lines = $entry['lines'] ?? [];
        $entry['line_count'] = count($lines);
        $entry['preview_lines'] = array_slice($lines, 0, $previewLineLimit);
        $entry['truncated'] = count($lines) > $previewLineLimit;

        return $entry;
    }

    /**
     * 解析常见日志首行。
     *
     * 作用：判断一行是否是“日志首行”，是则抽出时间/级别/摘要，否则返回 null。
     *
     * 为什么两套正则：laravel 有标准 `[时间] env.LEVEL: 消息` 格式可精确取级别；
     * 其它来源格式各异，退回到宽松的“时间戳 + 消息”并靠关键词猜级别。
     *
     * @param  string  $line  待解析行
     * @param  string  $source  来源标识
     * @return array|null 命中返回 { time, level, summary }，否则 null
     */
    private function parseLineHeader(string $line, string $source): ?array
    {
        // Laravel 标准格式：级别直接从 env.LEVEL 段取。
        if ($source === 'laravel' && preg_match('/^\[(?<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(?<env>[A-Za-z0-9_-]+)\.(?<level>[A-Za-z]+):\s*(?<message>.*)$/', $line, $matches)) {
            return [
                'time' => $matches['time'],
                'level' => strtoupper($matches['level']),
                'summary' => mb_strimwidth($matches['message'], 0, 180, '...'),
            ];
        }

        // 通用格式：允许方括号可选、时间用空格或 T 分隔，后接分隔符与消息。
        if (preg_match('/^\[?(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]?[:\s-]+(?<message>.*)$/', $line, $matches)) {
            return [
                // 把 ISO 的 T 换成空格，统一成可读时间。
                'time' => str_replace('T', ' ', $matches['time']),
                // 无显式级别字段，靠内容关键词推断。
                'level' => $this->detectLevel($line),
                'summary' => mb_strimwidth($matches['message'], 0, 180, '...'),
            ];
        }

        return null;
    }

    /**
     * 从日志内容中识别级别，用于前端标签颜色。
     *
     * 作用：在整行里按严重程度从高到低找第一个出现的级别关键词。
     *
     * 为什么按顺序遍历：一行里可能同时含多个词，优先返回更严重的级别，
     * 都没命中时兜底为 INFO。
     *
     * @param  string  $line  日志行
     * @return string 识别出的级别（大写）
     */
    private function detectLevel(string $line): string
    {
        $upper = mb_strtoupper($line);

        // 从最严重到最轻遍历，命中即返回，保证严重级别优先。
        foreach (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'] as $level) {
            if (str_contains($upper, $level)) {
                return $level;
            }
        }

        return 'INFO';
    }

    /**
     * 生成日志钻取所需的统计维度。
     *
     * 当前先提供级别分布与分钟级时间桶，后续接入 Loki / Prometheus 时，
     * 可以在这里继续扩展 service、host、trace_id 等标签。
     *
     * 作用：统计各级别数量与按分钟聚合的时间线，供前端画分布图与趋势图。
     *
     * @param  array  $entries  过滤后的事件
     * @param  string  $source  来源标识
     * @return array 含 source、levels（按量降序）、timeline（按时间升序）
     */
    private function facets(array $entries, string $source): array
    {
        $levels = [];
        $timeline = [];

        foreach ($entries as $entry) {
            // 累计级别分布。
            $level = strtoupper((string) ($entry['level'] ?? 'INFO'));
            $levels[$level] = ($levels[$level] ?? 0) + 1;

            // 把事件时间归到分钟桶再累计；无时间的事件不进时间线。
            $bucket = $this->minuteBucket($entry['time'] ?? null);

            if ($bucket !== null) {
                $timeline[$bucket] = ($timeline[$bucket] ?? 0) + 1;
            }
        }

        // 时间线按时间键升序，前端才能顺序画折线。
        ksort($timeline);

        return [
            'source' => $source,
            'levels' => collect($levels)
                ->map(fn (int $total, string $name): array => [
                    'name' => $name,
                    'total' => $total,
                ])
                ->sortByDesc('total')
                ->values()
                ->all(),
            'timeline' => collect($timeline)
                ->map(fn (int $total, string $bucket): array => [
                    'bucket' => $bucket,
                    'total' => $total,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * 将日志时间归并到分钟桶。
     *
     * 作用：取时间字符串前 16 个字符（YYYY-mm-dd HH:ii）作为分钟级聚合键。
     *
     * @param  string|null  $time  日志时间字符串
     * @return string|null 分钟桶键；无时间返回 null
     */
    private function minuteBucket(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        // "2026-07-30 12:34:56" 的前 16 位即 "2026-07-30 12:34"，精确到分钟。
        return substr($time, 0, 16);
    }

    /**
     * 返回统一空结果。
     *
     * 作用：文件缺失/不可读/来源非法等场景返回结构一致的空结果，前端无需分支处理。
     *
     * @param  string  $source  来源标识
     * @param  string  $message  给用户看的提示信息
     * @param  bool  $exists  文件是否存在
     * @param  bool  $readable  文件是否可读
     * @return array 结构与正常结果一致但内容为空的结果
     */
    public function emptyResult(string $source, string $message, bool $exists = false, bool $readable = false): array
    {
        return [
            'source' => $source,
            'path' => null,
            'exists' => $exists,
            'readable' => $readable,
            'message' => $message,
            'lines' => [],
            'entries' => [],
            'facets' => [
                'source' => $source,
                'levels' => [],
                'timeline' => [],
            ],
            'count' => 0,
            'entry_count' => 0,
            'pagination' => [
                'current_page' => 1,
                'per_page' => $this->normalizePerPage(20),
                'total' => 0,
                'last_page' => 1,
                'has_more' => false,
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * 空结果也走相同 per_page 限制。
     *
     * 作用：把 per_page 夹到 [5,100]，让空结果的分页字段与正常结果一致。
     *
     * @param  int  $perPage  每页条数
     * @return int 夹取后的每页条数
     */
    private function normalizePerPage(int $perPage): int
    {
        return max(5, min($perPage, 100));
    }
}
