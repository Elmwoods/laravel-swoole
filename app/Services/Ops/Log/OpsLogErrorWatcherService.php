<?php

namespace App\Services\Ops\Log;

use App\Events\Ops\AlertTriggered;
use App\Models\OpsAlert;
use App\Services\Ops\AlertNotificationService;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * 日志报错巡检服务（错误看门狗）。
 *
 * 运维中心告警链路的日志侧数据源：定时扫描各来源日志文件（laravel/worker/
 * scheduler/octane/build 等），用“增量偏移量”只读上次之后新增的字节，解析出
 * 错误/异常事件，去重后落库成 OpsAlert 并触发通知与广播。
 *
 * 关键设计：
 * - 增量读取靠 state 文件记录每个来源的 inode/offset/prefix_hash，实现断点续读，
 *   并能识别日志轮转（inode 变化或前缀哈希变化）后从头重扫。
 * - fingerprint 指纹用于同类报错去重（数字归一、缺表报错按表名归一）。
 * - 敏感信息（token/password/Bearer 等）在入库前脱敏。
 */
class OpsLogErrorWatcherService
{
    /**
     * 作用：注入告警通知服务，用于新告警首次出现时对外发送。
     *
     * @param  AlertNotificationService  $notification  告警多通道通知服务
     */
    public function __construct(
        private readonly AlertNotificationService $notification,
    ) {}

    /**
     * 扫描各来源日志、提取报错事件并（可选）落库。
     *
     * 作用：读取 state → 逐来源增量读取新字节 → 解析事件 → 写回 state → 非 dry-run 时落库告警。
     *
     * 为什么先写回 state 再落库：无论落库与否都要推进偏移量，避免下次重复扫描同一段日志。
     *
     * @param  array  $sources  指定要扫描的来源 key；空数组表示全部已配置来源
     * @param  bool  $dryRun  仅解析不落库（预览/测试用）
     * @param  bool  $resetOffsets  忽略已保存偏移量，从头重扫
     * @return array { scanned, detected, events, enabled }
     */
    public function scan(array $sources = [], bool $dryRun = false, bool $resetOffsets = false): array
    {
        // 配置总开关：关闭时直接返回 enabled=false，不做任何文件读取（boot-safe）。
        if (! (bool) config('ops.logs.error_watcher.enabled', true)) {
            return [
                'scanned' => 0,
                'detected' => 0,
                'events' => [],
                'enabled' => false,
            ];
        }

        $configured = $this->sources();
        // 未指定来源则扫描全部已配置来源。
        $selected = $sources === [] ? array_keys($configured) : $sources;
        // resetOffsets 时用空 state，等价于每个来源从头重扫。
        $state = $resetOffsets ? [] : $this->readState();
        $events = [];
        $scanned = 0;
        // 单次运行事件上限，防止一次拉取过多事件（例如日志突然刷屏）。
        $maxEvents = max(1, (int) config('ops.logs.error_watcher.max_events_per_run', 50));

        foreach ($selected as $source) {
            // 只处理白名单配置里的来源，忽略未知 key。
            if (! array_key_exists($source, $configured)) {
                continue;
            }

            $scanned += 1;
            $file = (string) $configured[$source];
            // 增量读取该来源自上次偏移之后的新内容（同时更新 $state 引用）。
            $read = $this->readNewBytes($source, $file, $state);

            if ($read['content'] === '') {
                continue;
            }

            foreach ($this->eventsFromContent($source, $read['content']) as $event) {
                $events[] = $event;

                // 达到单次上限就跳出双层循环，剩余偏移量仍会被写回，下次继续。
                if (count($events) >= $maxEvents) {
                    break 2;
                }
            }
        }

        // 无论是否 dry-run 都写回偏移量，保证不重复扫描已读过的字节。
        $this->writeState($state);

        // 非预览模式才真正落库并触发通知/广播。
        if (! $dryRun) {
            foreach ($events as $event) {
                $this->storeAlert($event);
            }
        }

        return [
            'scanned' => $scanned,
            'detected' => count($events),
            'events' => $events,
            'enabled' => true,
        ];
    }

    /**
     * 获取已配置的日志来源映射。
     *
     * 作用：从 config('ops.logs.error_watcher.sources') 读取 来源key => 文件路径 映射。
     *
     * @return array 来源 key 到文件路径的映射
     */
    public function sources(): array
    {
        return (array) config('ops.logs.error_watcher.sources', []);
    }

    /**
     * 为事件计算去重指纹。
     *
     * 作用：由 来源 + 级别 + 归一化文本 生成 sha1 指纹，供同类报错折叠与查重。
     *
     * @param  array  $event  事件数组
     * @return string sha1 指纹
     */
    public function fingerprint(array $event): string
    {
        return sha1(implode('|', [
            'logs',
            $event['source'] ?? '',
            $event['level'] ?? '',
            $this->fingerprintText($event),
        ]));
    }

    /**
     * 增量读取某来源日志自上次偏移之后的新字节。
     *
     * 作用：基于 state 里记录的 inode/offset/prefix_hash 判断能否续读，能则从 offset 读，
     * 否则从头读；读完把最新 size 作为新 offset 写回 state。
     *
     * 为什么用 inode + prefix_hash + size 三重判断：
     * - inode 变了说明日志被轮转成新文件；
     * - size < offset 说明文件被截断/重写；
     * - 前 512 字节哈希变了说明文件被原地重建（inode 可能被复用）。
     * 任一命中都把 offset 归零，避免漏读或错位读。
     *
     * @param  string  $source  来源 key
     * @param  string  $file  日志文件路径
     * @param  array  $state  引用传入的全局 state，会就地更新该来源条目
     * @return array { content: 新增文本 }
     */
    private function readNewBytes(string $source, string $file, array &$state): array
    {
        // 文件不存在/不可读：清掉该来源的旧偏移（可能已被删除），返回空。
        if (! is_file($file) || ! is_readable($file)) {
            unset($state[$source]);

            return ['content' => ''];
        }

        $size = filesize($file);
        $inode = fileinode($file);
        $previous = (array) ($state[$source] ?? []);
        $offset = (int) ($previous['offset'] ?? 0);

        // 取文件前 512 字节的哈希，用于识别 inode 复用下的“换文件”。
        $prefixHash = $this->filePrefixHash($file);

        // 检测日志轮转/截断/重建：命中任一条件就从头读。
        if (
            ($previous['inode'] ?? null) !== $inode          // 文件被轮转为新 inode
            || $size < $offset                                // 文件被截断，现有大小小于上次偏移
            || ($offset > 0 && ($previous['prefix_hash'] ?? null) !== $prefixHash) // 内容被原地重写
        ) {
            $offset = 0;
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return ['content' => ''];
        }

        // 从偏移位置读到文件末尾，即“上次以来的新增内容”。
        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        fclose($handle);

        // 写回本次读取后的状态：offset 推进到当前 size，下次从这里继续。
        $state[$source] = [
            'inode' => $inode,
            'size' => $size,
            'offset' => $size,
            'hash' => sha1($file.'|'.$inode.'|'.$size),
            'prefix_hash' => $prefixHash,
            'checked_at' => now()->toDateTimeString(),
        ];

        return ['content' => is_string($content) ? $content : ''];
    }

    /**
     * 从一段日志文本解析出错误事件列表。
     *
     * 作用：逐行扫描，命中错误首行时开一条事件，堆栈/续行并入当前事件，
     * 最后对相邻的 Docker build 报错做合并。
     *
     * 为什么先判 isStackContinuation：堆栈行里也可能含 “Exception” 等关键字，
     * 若不优先并入当前事件，会被误判成新的错误首行，把一条异常拆成多条。
     *
     * @param  string  $source  来源 key
     * @param  string  $content  增量读取到的日志文本
     * @return array 合并后的事件列表
     */
    private function eventsFromContent(string $source, string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);

        if (! is_array($lines)) {
            return [];
        }

        $events = [];
        $current = null;   // 正在累积的当前事件
        // 关注的级别白名单，统一大写后比对。
        $levels = array_map('strtoupper', (array) config('ops.logs.error_watcher.levels', []));

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // 已有当前事件、且这行是堆栈续行：并入当前事件，不当作新错误。
            if ($current !== null && $this->isStackContinuation($source, $line)) {
                $current['lines'][] = $line;

                continue;
            }

            $parsed = $this->parseErrorLine($line, $levels);

            if ($parsed !== null) {
                // 命中新错误首行：先把上一条累积的事件收尾。
                if ($current !== null) {
                    $events[] = $this->buildEvent($source, $current);
                }

                $current = [
                    'time' => $parsed['time'],
                    'level' => $parsed['level'],
                    'summary' => $parsed['summary'],
                    'lines' => [$line],
                    'rule' => $parsed['rule'],
                ];

                continue;
            }

            // 既非错误首行也没被识别为堆栈，但已有当前事件：作为普通续行并入。
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        // 循环结束后收尾最后一条未提交的事件。
        if ($current !== null) {
            $events[] = $this->buildEvent($source, $current);
        }

        // 合并相邻的 Docker build 报错（COPY/checksum 常拆成多段）。
        return $this->coalesceBuildEvents($events);
    }

    /**
     * 判定单行是否为“错误首行”并抽取字段。
     *
     * 作用：按三条规则依次尝试——Laravel 标准头取级别、行内关键词级别、异常关键词兜底。
     *
     * 为什么带 rule 字段：记录命中的是哪条规则，便于前端/排查区分误报来源与置信度。
     *
     * @param  string  $line  日志行
     * @param  array  $levels  关注的级别白名单（大写）
     * @return array|null 命中返回 { time, level, summary, rule }，否则 null
     */
    private function parseErrorLine(string $line, array $levels): ?array
    {
        // 规则一：Laravel 标准格式 `[时间] env.LEVEL: 消息`，级别须在白名单内。
        if (preg_match('/^\[(?<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+[A-Za-z0-9_-]+\.(?<level>[A-Za-z]+):\s*(?<message>.*)$/', $line, $matches)) {
            $level = strtoupper($matches['level']);

            // 级别不在关注白名单（如 INFO/DEBUG）就不算错误。
            if (! in_array($level, $levels, true)) {
                return null;
            }

            return [
                'time' => $matches['time'],
                'level' => $level,
                'summary' => mb_strimwidth($matches['message'], 0, 180, '...'),
                'rule' => 'laravel-level',
            ];
        }

        // 规则二：非标准格式但行内含 EMERGENCY/CRITICAL/ERROR 关键词，且在白名单内。
        if (preg_match('/\b(?<level>EMERGENCY|CRITICAL|ERROR)\b/i', $line, $matches)) {
            $level = strtoupper($matches['level']);

            if (! in_array($level, $levels, true)) {
                return null;
            }

            return [
                'time' => null,
                'level' => $level,
                'summary' => mb_strimwidth($line, 0, 180, '...'),
                'rule' => 'keyword-level',
            ];
        }

        // 规则三：兜底关键词（Exception / not found / failed），统一按 ERROR 处理。
        if (str_contains($line, 'Exception') || str_contains($line, 'not found') || str_contains($line, 'failed')) {
            return [
                'time' => null,
                'level' => 'ERROR',
                'summary' => mb_strimwidth($line, 0, 180, '...'),
                'rule' => 'exception-keyword',
            ];
        }

        return null;
    }

    /**
     * 把累积的原始事件组装成标准事件结构。
     *
     * 作用：截取前若干行上下文 → 脱敏 → 计算 severity/suggestion/fingerprint。
     *
     * @param  string  $source  来源 key
     * @param  array  $raw  eventsFromContent 累积的原始事件（含 time/level/summary/lines/rule）
     * @return array 标准事件（附 severity、snippet、suggestion、fingerprint 等）
     */
    private function buildEvent(string $source, array $raw): array
    {
        // 上下文只保留前 N 行（默认 3），避免把整段堆栈都塞进告警。
        $lines = array_slice($raw['lines'], 0, max(1, (int) config('ops.logs.error_watcher.context_lines', 3)));
        // 片段先脱敏，防止 token/密码等泄漏进告警存储。
        $snippet = $this->sanitizeText(implode(PHP_EOL, $lines));
        $event = [
            'source' => $source,
            'level' => strtoupper((string) $raw['level']),
            'severity' => $this->severity((string) $raw['level'], (string) $raw['summary']),
            'time' => $raw['time'],
            'summary' => $this->sanitizeText((string) $raw['summary']),
            'snippet' => mb_strimwidth($snippet, 0, 1000, '...'),
            'line_count' => count($raw['lines']),
            'rule' => $raw['rule'],
            'suggestion' => $this->suggestion((string) $raw['summary'], $snippet),
        ];
        // 指纹依赖上面已填好的字段，所以最后再算并回填。
        $event['fingerprint'] = $this->fingerprint($event);

        return $event;
    }

    /**
     * 合并相邻的 Docker build 报错事件。
     *
     * 作用：把 source=build 且属于 COPY/checksum 失败的事件并入上一条，减少重复告警。
     *
     * 为什么：Docker build 失败常把一个根因（如 COPY 找不到文件）打印成多段，
     * 分开告警会刷屏且指纹各异，合并后归为一条并重算 suggestion/fingerprint。
     *
     * @param  array  $events  buildEvent 产出的事件列表
     * @return array 合并后的事件列表
     */
    private function coalesceBuildEvents(array $events): array
    {
        $coalesced = [];

        foreach ($events as $event) {
            $lastIndex = count($coalesced) - 1;
            // 判断该事件是否为 Docker COPY/checksum 类失败片段。
            $isDockerCopy = str_contains($event['snippet'], 'failed to calculate checksum')
                || str_contains($event['snippet'], 'COPY');

            // 仅 build 来源、命中特征、且前面已有事件时才并入上一条。
            if ($event['source'] === 'build' && $isDockerCopy && $lastIndex >= 0) {
                // 片段追加并整体截断到 1000 字。
                $coalesced[$lastIndex]['snippet'] = mb_strimwidth(
                    $coalesced[$lastIndex]['snippet'].PHP_EOL.$event['snippet'],
                    0,
                    1000,
                    '...',
                );
                $coalesced[$lastIndex]['line_count'] += $event['line_count'];
                // 内容变了，重算建议与指纹。
                $coalesced[$lastIndex]['suggestion'] = $this->suggestion($coalesced[$lastIndex]['summary'], $coalesced[$lastIndex]['snippet']);
                $coalesced[$lastIndex]['fingerprint'] = $this->fingerprint($coalesced[$lastIndex]);

                continue;
            }

            $coalesced[] = $event;
        }

        return $coalesced;
    }

    /**
     * 把事件落库为 OpsAlert，并在首次出现时通知与广播。
     *
     * 作用：按 fingerprint 查重 upsert；已存在则累加 hit_count 与刷新 last_seen_at，
     * 首次出现才发通知；最后广播实时事件。
     *
     * 为什么 shouldNotify 取 !$alert->exists：只在告警首次产生时通知，避免同类报错反复刷屏；
     * 广播用 try/catch 兜底——告警已落库，广播失败不能中断整个扫描命令。
     *
     * @param  array  $event  标准事件
     * @return OpsAlert 落库后的告警模型
     */
    private function storeAlert(array $event): OpsAlert
    {
        // 按指纹查重：存在则更新，不存在则新建。
        $alert = OpsAlert::query()->firstOrNew([
            'fingerprint' => $event['fingerprint'],
        ]);
        // 只有全新告警才需要对外通知。
        $shouldNotify = ! $alert->exists;

        $alert->fill([
            'fingerprint' => $event['fingerprint'],
            'source' => 'logs:'.$event['source'],
            'severity' => $event['severity'],
            'title' => '日志报错：'.$event['source'].' '.$event['level'],
            'message' => $event['summary'].' 建议：'.$event['suggestion'],
            'context' => [
                'source' => $event['source'],
                'level' => $event['level'],
                'time' => $event['time'],
                'summary' => $event['summary'],
                'snippet' => $event['snippet'],
                'rule' => $event['rule'],
                'suggestion' => $event['suggestion'],
            ],
            'status' => 'open',
            'last_seen_at' => now(),
            // 已存在则命中次数 +1，新建从 1 起。
            'hit_count' => $alert->exists ? $alert->hit_count + 1 : 1,
        ]);
        $alert->save();

        if ($shouldNotify) {
            try {
                // 发通知并记录各通道结果。
                $notification = $this->notification->send($alert);
                $this->recordNotificationResult($alert, $notification);
            } catch (Throwable $e) {
                // 通知过程抛异常也要记录失败原因（脱敏后），不向外冒泡中断扫描。
                $this->recordNotificationResult($alert, [
                    'error' => [
                        'sent' => false,
                        'reason' => 'notification_exception',
                        'message' => $this->sanitizeText($e->getMessage()),
                    ],
                ]);
            }
        }

        try {
            broadcast(new AlertTriggered($alert));
        } catch (Throwable) {
            // 告警已经落库；广播失败不能阻断日志扫描命令。
        }

        return $alert;
    }

    /**
     * 记录通知结果，标记“真正失败”的通道。
     *
     * 作用：从各通道结果里挑出第一条真实失败的，把失败标记与原因写回告警 context。
     *
     * 为什么排除某些 reason：channel_disabled_by_policy / telegram_not_configured /
     * mail_recipient_empty 属于“未配置/被策略禁用”的预期情况，不算异常失败，不应标红。
     *
     * @param  OpsAlert  $alert  告警模型
     * @param  array  $result  send() 返回的各通道结果
     */
    private function recordNotificationResult(OpsAlert $alert, array $result): void
    {
        // 找第一条 sent=false 且原因不属于“预期未配置”白名单的通道。
        $failed = collect($result)
            ->first(fn (array $channel): bool => ($channel['sent'] ?? true) === false
                && isset($channel['reason'])
                && ! in_array($channel['reason'], ['channel_disabled_by_policy', 'telegram_not_configured', 'mail_recipient_empty'], true));

        // 没有真正失败的通道，无需改动 context。
        if ($failed === null) {
            return;
        }

        // 把失败标记与原因合并进 context，便于前端展示“通知未送达”。
        $context = (array) $alert->context;
        $context['notification_failed'] = true;
        $context['notification_reason'] = (string) ($failed['reason'] ?? 'notification_failed');
        $alert->context = $context;
        $alert->save();
    }

    /**
     * 计算告警严重级别。
     *
     * 作用：级别或摘要里含 EMERGENCY/CRITICAL 归为 critical，否则 warning。
     *
     * @param  string  $level  日志级别
     * @param  string  $summary  摘要
     * @return string 'critical' 或 'warning'
     */
    private function severity(string $level, string $summary): string
    {
        // 合并级别与摘要一起判断，兼顾非标准来源里级别写在正文的情况。
        $upper = strtoupper($level.' '.$summary);

        if (str_contains($upper, 'EMERGENCY') || str_contains($upper, 'CRITICAL')) {
            return 'critical';
        }

        return 'warning';
    }

    /**
     * 根据报错内容给出处置建议。
     *
     * 作用：匹配已知错误特征，返回对应的排查/修复提示；未命中给通用建议。
     *
     * 为什么维护这张匹配表：把团队踩过的高频坑（CSV escape、缺表迁移、Docker build
     * context、Reverb 未启动等）固化成即时建议，缩短告警到定位的时间。
     *
     * @param  string  $summary  摘要
     * @param  string  $snippet  上下文片段
     * @return string 处置建议
     */
    private function suggestion(string $summary, string $snippet): string
    {
        $text = $summary.PHP_EOL.$snippet;

        // 按已知错误特征逐条匹配，命中即返回对应建议。
        return match (true) {
            str_contains($text, 'fputcsv()') => '检查 CSV 导出，显式传入 fputcsv escape 参数。',
            str_contains($text, 'Log [deprecations] is not defined') => '检查 logging 配置，确保 deprecations channel 已定义。',
            str_contains($text, 'iostat: not found') => '检查高级系统监控，缺少 iostat 时应走可用性兜底。',
            str_contains($text, 'failed to calculate checksum') || str_contains($text, '"/start-container": not found') => '检查 Docker build context，使用 docker compose build laravel.test 或 docker build -f docker/8.4/Dockerfile docker/8.4。',
            str_contains($text, 'SQLSTATE[42S02]') => '检查数据库迁移是否已执行，缺失表需要运行 artisan migrate。',
            str_contains($text, 'Connection refused') && str_contains($text, 'mysql') => '检查 MySQL 容器或数据库连接配置。',
            str_contains($text, 'Connection refused') && str_contains($text, '6001') => '检查 Reverb/Pusher 服务是否启动。',
            default => '检索日志 fingerprint 和摘要，定位对应服务、配置或最近发布变更。',
        };
    }

    /**
     * 对文本做敏感信息脱敏。
     *
     * 作用：把 token/password/secret/api_key/auth_signature 的值与 Bearer 令牌替换为 [FILTERED]。
     *
     * 为什么：告警片段会落库并可能对外通知，必须先抹掉凭据，避免二次泄漏。
     *
     * @param  string  $text  原始文本
     * @return string 脱敏后的文本
     */
    private function sanitizeText(string $text): string
    {
        // 匹配 key=value 形式的凭据参数，只替换值部分。
        $text = preg_replace('/(token|password|secret|api[_-]?key|auth_signature)=([^&\s"]+)/i', '$1=[FILTERED]', $text) ?? $text;
        // 匹配 Authorization 里的 Bearer 令牌。
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [FILTERED]', $text) ?? $text;

        return $text;
    }

    /**
     * 归一化用于指纹的文本。
     *
     * 作用：把所有数字替换为 #、转小写，使仅数字不同的同类报错得到相同指纹。
     *
     * 为什么：行号/ID/时间戳等数字会让本质相同的错误指纹各异，归一后才能正确去重。
     *
     * @param  string  $text  原始文本
     * @return string 归一后的文本
     */
    private function normalizeFingerprintText(string $text): string
    {
        $text = preg_replace('/\d+/', '#', $text) ?? $text;

        return mb_strtolower($text);
    }

    /**
     * 生成事件的指纹文本部分。
     *
     * 作用：优先用“缺表报错”的规范指纹，否则退回对 summary 的通用归一。
     *
     * @param  array  $event  事件
     * @return string 指纹文本
     */
    private function fingerprintText(array $event): string
    {
        $summary = (string) ($event['summary'] ?? '');
        $snippet = (string) ($event['snippet'] ?? '');
        // 缺表报错单独归一（按表名），使同一张缺失表的多处报错折叠成一条。
        $canonical = $this->databaseMissingTableFingerprintText($summary.PHP_EOL.$snippet);

        if ($canonical !== null) {
            return $canonical;
        }

        return $this->normalizeFingerprintText($summary);
    }

    /**
     * 为“数据库缺表”报错生成规范指纹文本。
     *
     * 作用：命中 SQLSTATE[42S02] 且能解析出表名时，返回按表名归一的稳定指纹。
     *
     * 为什么：同一张缺失表会在不同 SQL/位置反复报错，摘要文本各异；
     * 按表名归一后它们折叠成一条告警，指向同一个“跑迁移”动作。
     *
     * @param  string  $text  summary + snippet 合并文本
     * @return string|null 命中返回规范指纹，否则 null
     */
    private function databaseMissingTableFingerprintText(string $text): ?string
    {
        // 非缺表错误码直接跳过。
        if (! str_contains($text, 'SQLSTATE[42S02]')) {
            return null;
        }

        // 从报错里抽出表名（形如 Table 'xxx' doesn't exist）。
        if (! preg_match("/Table ['\"](?<table>[^'\"]+)['\"] doesn't exist/i", $text, $matches)) {
            return null;
        }

        return 'sqlstate-42s02-missing-table:'.mb_strtolower((string) $matches['table']);
    }

    /**
     * 判定某行是否为异常堆栈/续行。
     *
     * 作用：对多行日志来源，识别属于上一条错误的堆栈帧或续行，以便并入而非拆分。
     *
     * 为什么先排除“标准日志首行”：带 `[时间] env.LEVEL:` 的行一定是新日志开头，
     * 绝不能当作续行，否则会把两条独立日志错误地合并。
     *
     * @param  string  $source  来源 key
     * @param  string  $line  日志行
     * @return bool 是堆栈/续行则 true
     */
    private function isStackContinuation(string $source, string $line): bool
    {
        // 只有这些多行来源才会有堆栈续行，其它来源一律按单行处理。
        if (! in_array($source, ['laravel', 'worker', 'scheduler', 'octane'], true)) {
            return false;
        }

        // 命中标准日志首行格式：这是新日志的开头，不是续行。
        if (preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+[A-Za-z0-9_-]+\.[A-Za-z]+:/', $line)) {
            return false;
        }

        // 典型堆栈/续行特征：#N 帧、Stack trace、Next/Caused by、[stacktrace]、代码指示符 ▕、{main}，
        // 以及含 Exception/SQLSTATE 或容器内 vendor/app 路径的行。
        return preg_match('/^(#\d+\s|Stack trace:|Next |Caused by:|\[stacktrace\]|\[previous exception\]|\d+▕|\{main\})/i', $line) === 1
            || str_contains($line, 'Exception')
            || str_contains($line, 'SQLSTATE[')
            || str_contains($line, '/var/www/html/vendor/')
            || str_contains($line, '/var/www/html/app/');
    }

    /**
     * 读取增量扫描的偏移量状态文件。
     *
     * 作用：从 state_file 读回上次各来源的 inode/offset/prefix_hash；缺失或损坏时返回空数组。
     *
     * @return array 各来源的状态映射
     */
    private function readState(): array
    {
        $file = (string) config('ops.logs.error_watcher.state_file');

        // 状态文件不存在/不可读（如首次运行）：当作全新，从头扫。
        if (! is_file($file) || ! is_readable($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        // JSON 损坏时兜底空数组，避免抛错中断扫描。
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 写回增量扫描的偏移量状态文件。
     *
     * 作用：把最新 state 以 JSON 落盘；目录不存在时先创建。
     *
     * @param  array  $state  待持久化的各来源状态
     */
    private function writeState(array $state): void
    {
        $file = (string) config('ops.logs.error_watcher.state_file');
        $directory = dirname($file);

        // 首次运行目录可能不存在，先递归创建。
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // 不转义斜杠，保持路径可读。
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 计算文件前 512 字节的哈希。
     *
     * 作用：用文件头部指纹识别“inode 相同但内容已被换掉”的日志重建情况。
     *
     * 为什么只取 512 字节：轮转/重建时头部必变，取少量字节即可判定且开销极小。
     *
     * @param  string  $file  文件路径
     * @return string 前缀 sha1；打不开返回空串
     */
    private function filePrefixHash(string $file): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        $prefix = fread($handle, 512);
        fclose($handle);

        return sha1(is_string($prefix) ? $prefix : '');
    }
}
