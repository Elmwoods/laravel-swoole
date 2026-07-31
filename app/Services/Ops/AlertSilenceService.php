<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlert;
use App\Models\OpsAlertSilence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * 告警值班静默窗口：维护窗口内命中的告警只入库/广播、不外发到通道。
 *
 * 在 raise/notify/broadcast 管线中，本类作用于 notify 环，与 AlertCorrelationService 并列为
 * 两道「是否外发」的闸门：关联抑制看「上游因果」，静默看「值班排班时间窗」。命中静默的告警
 * 依然会 raise 入库、依然 broadcast 到面板（值班仍看得见），仅仅不再推送到外部通道（如 IM/邮件），
 * 用于计划内维护、夜间免打扰等场景。
 *
 * 判断在 AlertNotificationService::send() 的 choke point 调用，boot-safe（表缺失/出错→不静默，绝不阻断告警）。
 */
class AlertSilenceService
{
    private const SEVERITIES = ['critical', 'warning', 'info']; // 允许的告警级别白名单（校验 severities 过滤器用）

    private const RECURRENCES = ['once', 'daily', 'weekly']; // 允许的重复模式：一次性 / 每天 / 每周

    /**
     * 作用：判断该告警此刻是否被某个生效中的静默规则命中（命中则外发被抑制）。
     *
     * 为什么先用 SQL 预筛绝对窗口、再在 PHP 里判周期：is_active + starts_at/ends_at 是可索引的粗筛，
     * 而「每天/每周的时刻窗」依赖当前 H:i 与星期，难以纯 SQL 表达，故拉出候选后逐条精判。
     *
     * @param  OpsAlert  $alert  待判定的告警
     * @return bool true=被静默（不外发）；false=不静默
     */
    public function isSilenced(OpsAlert $alert): bool
    {
        try {
            // boot-safe：静默表还没迁移出来时直接放行，绝不因缺表而吞掉告警。
            if (! Schema::hasTable('ops_alert_silences')) {
                return false;
            }

            $now = now();

            // 粗筛：只取此刻处于绝对生效区间 [starts_at, ends_at] 内且启用的静默。
            $silences = OpsAlertSilence::query()
                ->where('is_active', true)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->get();

            foreach ($silences as $silence) {
                // 精判：既要满足周期时刻窗（matchesRecurrence），又要来源/级别匹配（matches），才算命中。
                if ($this->matchesRecurrence($silence, $now)
                    && $this->matches($silence, (string) $alert->source, (string) $alert->severity)) {
                    return true; // 命中任一静默即可短路返回
                }
            }

            return false;
        } catch (Throwable) {
            return false; // boot-safe：任何异常都按「不静默」处理
        }
    }

    /**
     * 作用：返回当前真正生效中的静默（绝对窗口 + 周期时刻窗都命中），供前端 banner / 页面高亮。
     *
     * 为什么在 filter 里再判 matchesRecurrence：SQL 只筛得了绝对窗口，周期性静默还可能此刻不在「每天时刻窗」内，
     * 这类要从「生效中」剔除，否则前端会误报「正在静默」。
     *
     * @return array<int, array<string, mixed>> 序列化后的生效静默列表
     */
    public function activeSilences(): array
    {
        $now = now();

        return OpsAlertSilence::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderByDesc('starts_at')
            ->get()
            ->filter(fn (OpsAlertSilence $s): bool => $this->matchesRecurrence($s, $now)) // 二次过滤周期时刻窗
            ->map(fn (OpsAlertSilence $s): array => $this->serialize($s))
            ->values()
            ->all();
    }

    /**
     * 作用：列出全部静默规则（不论是否生效），按开始时间倒序，供管理页展示。
     *
     * @return array<int, array<string, mixed>> 序列化后的全部静默列表
     */
    public function list(): array
    {
        return OpsAlertSilence::query()
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (OpsAlertSilence $s): array => $this->serialize($s))
            ->all();
    }

    /**
     * 作用：创建一条静默规则，校验时间/周期参数后落库。
     *
     * 为什么在这里做参数分支校验：once 只需绝对窗口；daily/weekly 还必须有每天的时刻窗，weekly 更要选中星期几，
     * 缺失即抛异常，避免落库后运行期才发现「周期静默永不命中」的哑规则。
     *
     * @param  array<string, mixed>  $data  表单数据（starts_at/ends_at/recurrence/start_time/end_time/days_of_week/sources/severities/label/is_active）
     * @param  AdminUser|null  $actor  创建人；记入 created_by
     * @return OpsAlertSilence 新建的静默模型
     *
     * @throws InvalidArgumentException 当时间区间非法或周期参数缺失时
     */
    public function create(array $data, ?AdminUser $actor = null): OpsAlertSilence
    {
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        // 绝对窗口硬校验：起止必填，且结束必须严格晚于开始（用 strtotime 归一成时间戳比较）。
        if ($startsAt === null || $endsAt === null || strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
            throw new InvalidArgumentException('结束时间必须晚于开始时间。');
        }

        $recurrence = (string) ($data['recurrence'] ?? 'once');
        // 非白名单的 recurrence 一律降级为 once，防止注入非法周期模式。
        $recurrence = in_array($recurrence, self::RECURRENCES, true) ? $recurrence : 'once';
        $startTime = null;
        $endTime = null;
        $daysOfWeek = null;

        // 仅周期性静默（daily/weekly）才需要每天的时刻窗与星期集合；once 不需要。
        if ($recurrence !== 'once') {
            $startTime = (string) ($data['start_time'] ?? '');
            $endTime = (string) ($data['end_time'] ?? '');

            if ($startTime === '' || $endTime === '') {
                throw new InvalidArgumentException('周期性静默必须设置每天的开始与结束时刻。');
            }

            if ($recurrence === 'weekly') {
                // 清洗星期集合：转 int、只保留 0–6（周日=0…周六=6）、去重排序。
                $daysOfWeek = collect((array) ($data['days_of_week'] ?? []))
                    ->map(fn ($d): int => (int) $d)
                    ->filter(fn (int $d): bool => $d >= 0 && $d <= 6)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($daysOfWeek === []) {
                    throw new InvalidArgumentException('每周静默必须至少选择一天。');
                }
            }
        }

        return OpsAlertSilence::query()->create([
            // label 去空白后为空则存 null，避免存入无意义空串。
            'label' => isset($data['label']) && trim((string) $data['label']) !== '' ? trim((string) $data['label']) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'recurrence' => $recurrence,
            'days_of_week' => $daysOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'sources' => $this->cleanStrings($data['sources'] ?? []), // 来源过滤器：清洗成干净字符串数组（空=匹配所有来源）
            // 级别过滤器：清洗后再与白名单取交集，剔除非法 severity（空=匹配所有级别）。
            'severities' => array_values(array_intersect($this->cleanStrings($data['severities'] ?? []), self::SEVERITIES)),
            'is_active' => (bool) ($data['is_active'] ?? true), // 缺省即启用
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * 作用：启用/停用某条静默。
     *
     * @param  int  $id  静默主键
     * @param  bool  $active  目标启用状态
     * @return bool 是否有行被更新（id 不存在则 false）
     */
    public function toggle(int $id, bool $active): bool
    {
        return OpsAlertSilence::query()->whereKey($id)->update(['is_active' => $active]) > 0;
    }

    /**
     * 作用：删除某条静默。
     *
     * @param  int  $id  静默主键
     * @return bool 是否有行被删除（id 不存在则 false）
     */
    public function delete(int $id): bool
    {
        return OpsAlertSilence::query()->whereKey($id)->delete() > 0;
    }

    /**
     * 作用：在绝对窗口（SQL 已预筛）之上，按重复模式判断此刻是否落在周期时刻窗内。
     *
     * 为什么 once 直接返回 true：一次性静默没有周期时刻概念，落在绝对窗口内即命中，无需再判时刻/星期。
     *
     * @param  OpsAlertSilence  $silence  待判定的静默
     * @param  CarbonInterface  $now  当前时间
     * @return bool 是否命中周期时刻窗
     */
    private function matchesRecurrence(OpsAlertSilence $silence, CarbonInterface $now): bool
    {
        $recurrence = (string) ($silence->recurrence ?? 'once');

        if ($recurrence === 'once') {
            return true; // 绝对窗口已由 SQL 预筛
        }

        // daily/weekly 都要求当前时刻落在每天的 [start_time,end_time] 内，否则不命中。
        if (! $this->timeInWindow($now, (string) $silence->start_time, (string) $silence->end_time)) {
            return false;
        }

        if ($recurrence === 'weekly') {
            // weekly 还需当天星期命中所选集合（dayOfWeek：周日=0…周六=6）。
            $days = array_map('intval', (array) ($silence->days_of_week ?? []));

            return in_array((int) $now->dayOfWeek, $days, true);
        }

        return true; // daily：过了时刻窗判断即命中，无需再看星期
    }

    /**
     * 作用：判断当前 H:i 是否落在每天的 [start,end] 时刻窗内（按 "HH:MM" 字符串字典序比较）。
     *
     * 为什么 start>end 走「或」逻辑：这代表跨午夜的窗口（如 22:00→06:00），此时命中条件是
     * 「晚于 start（当晚段）或 早于 end（次日凌晨段）」，而非普通的「介于两者之间」。
     *
     * @param  CarbonInterface  $now  当前时间
     * @param  string  $start  窗口起始时刻 "HH:MM"
     * @param  string  $end  窗口结束时刻 "HH:MM"
     * @return bool 是否落在时刻窗内；任一端为空则视为不命中
     */
    private function timeInWindow(CarbonInterface $now, string $start, string $end): bool
    {
        if ($start === '' || $end === '') {
            return false; // 时刻窗不完整，无法判定，按不命中处理
        }

        $t = $now->format('H:i'); // 同为 "HH:MM" 定宽格式，字典序即时间序，可直接字符串比较

        return $start <= $end
            ? ($t >= $start && $t <= $end)   // 同日窗口：介于 [start,end]
            : ($t >= $start || $t <= $end);  // 跨午夜窗口：晚于 start 或 早于 end
    }

    /**
     * 作用：判断静默的来源/级别过滤器是否与该告警匹配。
     *
     * 为什么空数组视为「匹配所有」：过滤器留空表示不限定该维度，这是「全量静默」的常见配置，
     * 因此空集合应命中一切来源/级别，而非命中零个。
     *
     * @param  OpsAlertSilence  $silence  待判定的静默
     * @param  string  $source  告警来源
     * @param  string  $severity  告警级别
     * @return bool 来源与级别是否都匹配
     */
    private function matches(OpsAlertSilence $silence, string $source, string $severity): bool
    {
        $sources = (array) ($silence->sources ?? []);
        $severities = (array) ($silence->severities ?? []);

        $sourceOk = $sources === [] || in_array($source, $sources, true);       // 空=不限来源
        $severityOk = $severities === [] || in_array($severity, $severities, true); // 空=不限级别

        return $sourceOk && $severityOk; // 两维度都需匹配才算命中
    }

    /**
     * 作用：把任意输入清洗成去空白、去空串、去重的字符串数组，供 sources/severities 过滤器使用。
     *
     * @param  mixed  $value  原始输入（数组或标量，容错）
     * @return array<int, string> 清洗后的字符串数组（连续下标）
     */
    private function cleanStrings(mixed $value): array
    {
        return collect((array) $value)
            ->map(fn ($v): string => trim((string) $v))
            ->filter(fn (string $v): bool => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 作用：把静默模型序列化成前端可直接消费的数组（日期转字符串、JSON 字段归一）。
     *
     * @param  OpsAlertSilence  $silence  静默模型
     * @return array<string, mixed> 序列化结果
     */
    private function serialize(OpsAlertSilence $silence): array
    {
        return [
            'id' => $silence->id,
            'label' => $silence->label,
            'starts_at' => optional($silence->starts_at)->toDateTimeString(),
            'ends_at' => optional($silence->ends_at)->toDateTimeString(),
            'recurrence' => $silence->recurrence ?? 'once',
            'days_of_week' => array_map('intval', (array) ($silence->days_of_week ?? [])),
            'start_time' => $silence->start_time,
            'end_time' => $silence->end_time,
            'sources' => (array) ($silence->sources ?? []),
            'severities' => (array) ($silence->severities ?? []),
            'is_active' => (bool) $silence->is_active,
            'created_at' => optional($silence->created_at)->toDateTimeString(),
        ];
    }
}
