<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsOnCallShift;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * 值班排班：维护值班班次（绝对时间段 / 每天 / 每周重复），供新告警自动指派解析「当前值班人」。
 *
 * 在告警子系统中的定位（raise 告警 -> notify 通知 -> broadcast 广播 管线里的「找人」环节）：
 *  - raise 告警：AlertCenterService::storeAlert 创建告警时调用 currentOnCall()，
 *    把「此刻在岗的人」写入 assigned_to，从而决定 notify 阶段该 @ 谁、该把告警派给谁。
 *  - notify 通知：sendDueReminders() 主动给即将上岗的人推送提醒，属于通知管线的一支，
 *    实际发送委托给 AlertNotificationService（通道/开关由它统一把关）。
 *  - 本类不直接决定「是否发送」的告警级门禁，只负责回答「现在谁值班」这个事实问题。
 *
 * boot-safe：所有对外方法都用 try/catch 包裹，表缺失或异常一律降级为 null / 0，
 * 绝不因排班数据问题阻断告警的创建与通知（告警链路可用性优先于排班准确性）。
 */
class OnCallRotationService
{
    // 支持的重复模式：一次性绝对窗口 / 每天固定时段 / 每周指定星期几的固定时段。
    private const RECURRENCES = ['once', 'daily', 'weekly'];

    /**
     * 作用：注入通知服务，值班提醒的实际下发全部委托给它（本类不直接碰通道）。
     *
     * @param  AlertNotificationService  $notification  告警/值班通知的统一出口，负责通道选择与开关门禁
     */
    public function __construct(
        private readonly AlertNotificationService $notification,
    ) {}

    /**
     * 作用：向「即将上岗」（lead_minutes 内即将开始）的一次性班次值班人推送上岗提醒，并按 reminded_at 去重。
     *
     * 为什么只处理 once：重复班次（daily/weekly）会反复上岗，单个 reminded_at 字段无法按「每一次上岗」去重，
     * 若纳入会造成重复提醒或漏提醒，因此周期性班次的提醒不在此路径处理。
     *
     * @return int 本次实际推送的提醒条数（配置关闭 / 表缺失 / 异常时返回 0）
     */
    public function sendDueReminders(): int
    {
        try {
            // 通知门禁：整个上岗提醒特性默认关闭，未显式开启则直接短路，一条都不发。
            if (! (bool) config('ops.alerts.on_call_reminder.enabled', false)) {
                return 0;
            }

            // boot-safe 守卫：迁移未跑、表尚不存在时安静返回，避免在启动期抛异常。
            if (! Schema::hasTable('ops_on_call_shifts')) {
                return 0;
            }

            // 提前量（分钟）：至少 1 分钟，决定「多久之前算即将上岗」的时间窗宽度。
            $lead = max(1, (int) config('ops.alerts.on_call_reminder.lead_minutes', 15));
            $now = now();

            // 仅 once 绝对班次（recurring 无法用单 reminded_at 按次去重）。
            $shifts = OpsOnCallShift::query()
                ->where('is_active', true)
                ->where('recurrence', 'once')
                ->whereNull('reminded_at')                                       // 去重关键：已提醒过（reminded_at 非空）的不再发
                ->whereBetween('starts_at', [$now, $now->copy()->addMinutes($lead)]) // 开始时间落在 [现在, 现在+提前量] 的窗口内
                ->get();

            $sent = 0;

            foreach ($shifts as $shift) {
                // 委托通知服务下发；此处只传值班人与班次开始时间，是否真正送达由通知层把关。
                $this->notification->sendOnCallReminder(
                    (string) $shift->assignee,
                    optional($shift->starts_at)->toDateTimeString() ?? '',
                );
                // 立即打上 reminded_at 时间戳作为去重标记，保证同一班次不会被再次提醒。
                $shift->forceFill(['reminded_at' => now()])->save();
                $sent++;
            }

            return $sent;
        } catch (Throwable) {
            // 任意异常都降级为 0，保证提醒失败不冒泡、不影响调度器的其它任务。
            return 0;
        }
    }

    /**
     * 作用：解析「此刻在岗的值班人」标识，是 raise 阶段自动指派 assigned_to 的数据来源。
     *
     * 为什么要 boot-safe：本方法在告警创建的热路径上被调用，任何异常都必须降级为「无人值班」（null），
     * 绝不能因排班查询失败而把告警的创建/通知一起拖垮。
     *
     * @return string|null 当前值班人标识；无匹配班次或异常时为 null
     */
    public function currentOnCall(): ?string
    {
        try {
            return $this->currentShift()?->assignee;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 作用：返回此刻真正生效的班次实体（先用 SQL 预筛绝对生效范围，再按重复模式精筛）。
     *
     * 为什么两段式：SQL 只能筛绝对时间窗（starts_at/ends_at），而 daily/weekly 的「每天时段、星期几」
     * 无法用简单 SQL 表达，故先按开始时间倒序取候选，再在 PHP 里挑第一个命中重复规则的班次（最近开始者优先）。
     *
     * @return OpsOnCallShift|null 命中的班次；表缺失或无命中时为 null
     */
    private function currentShift(): ?OpsOnCallShift
    {
        // boot-safe 守卫：表不存在直接返回 null。
        if (! Schema::hasTable('ops_on_call_shifts')) {
            return null;
        }

        $now = now();

        $shifts = OpsOnCallShift::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)   // 绝对生效范围预筛：已开始
            ->where('ends_at', '>=', $now)     // 且未结束
            ->orderByDesc('starts_at')          // 最近开始的排前面
            ->orderByDesc('id')                 // 同开始时间时用 id 兜底，保证顺序确定
            ->get();

        foreach ($shifts as $shift) {
            // 逐个按重复模式精筛，取第一个命中者（因已按开始时间倒序，即「最近生效」的班次）。
            if ($this->matchesRecurrence($shift, $now)) {
                return $shift;
            }
        }

        return null;
    }

    /**
     * 作用：列出全部班次（含已停用/历史），并标注当前正在生效的那一条，供管理界面展示。
     *
     * @return array<int, array<string, mixed>> 每个元素为 serialize() 序列化后的班次
     */
    public function list(): array
    {
        // 先安全求出当前生效班次 id，用于在列表里给正在值班的那条打 current 标记。
        $currentId = $this->safeCurrentShiftId();

        return OpsOnCallShift::query()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OpsOnCallShift $shift): array => $this->serialize($shift, $currentId))
            ->all();
    }

    /**
     * 作用：校验并创建一条值班班次；根据 recurrence 决定需要哪些字段，非法输入直接抛异常。
     *
     * 为什么在此严格校验：班次一旦落库就会参与 currentOnCall() 的「找人」判定，
     * 脏数据（如结束早于开始、周期班次缺时段）会导致派单错人，故在入口处一次性拦截。
     *
     * @param  array<string, mixed>  $data  班次原始输入（assignee/starts_at/ends_at/recurrence/时段/星期 等）
     * @param  AdminUser|null  $actor  操作者，用于记录 created_by（可空，如系统内部创建）
     * @return OpsOnCallShift 新建的班次实体
     *
     * @throws InvalidArgumentException 时间区间非法、或周期班次缺少必填时段/星期时抛出
     */
    public function create(array $data, ?AdminUser $actor = null): OpsOnCallShift
    {
        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        // 边界校验：起止都必须给出，且结束严格晚于开始（用 strtotime 比较时间戳）。
        if ($startsAt === null || $endsAt === null || strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
            throw new InvalidArgumentException('结束时间必须晚于开始时间。');
        }

        // 归一化重复模式：非白名单值一律回落为 once，避免存入未知模式导致后续判定失控。
        $recurrence = (string) ($data['recurrence'] ?? 'once');
        $recurrence = in_array($recurrence, self::RECURRENCES, true) ? $recurrence : 'once';
        $startTime = null;
        $endTime = null;
        $daysOfWeek = null;

        // 仅周期性班次（daily/weekly）才需要「每天的时段」；once 用绝对起止即可。
        if ($recurrence !== 'once') {
            $startTime = (string) ($data['start_time'] ?? '');
            $endTime = (string) ($data['end_time'] ?? '');

            if ($startTime === '' || $endTime === '') {
                throw new InvalidArgumentException('周期性值班必须设置每天的开始与结束时刻。');
            }

            if ($recurrence === 'weekly') {
                // 星期集合清洗：转 int -> 只留 0..6（周日..周六）-> 去重 -> 排序，得到规范化的星期数组。
                $daysOfWeek = collect((array) ($data['days_of_week'] ?? []))
                    ->map(fn ($d): int => (int) $d)
                    ->filter(fn (int $d): bool => $d >= 0 && $d <= 6)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                // 每周班次至少要选一天，否则永远匹配不到任何时刻，等于无效班次。
                if ($daysOfWeek === []) {
                    throw new InvalidArgumentException('每周值班必须至少选择一天。');
                }
            }
        }

        return OpsOnCallShift::query()->create([
            'assignee' => trim((string) $data['assignee']),
            // label 为空白字符串时归一为 null（避免存入无意义的空标签，便于前端判断有无备注）。
            'label' => isset($data['label']) && trim((string) $data['label']) !== '' ? trim((string) $data['label']) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'recurrence' => $recurrence,
            'days_of_week' => $daysOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * 作用：启用/停用某条班次（停用即从「找人」候选中剔除，但不删除历史记录）。
     *
     * @param  int  $id  班次主键
     * @param  bool  $active  目标启用状态
     * @return bool 是否有记录被更新（id 不存在时为 false）
     */
    public function toggle(int $id, bool $active): bool
    {
        // 返回受影响行数 > 0 即视为成功，id 不存在则更新 0 行 -> false。
        return OpsOnCallShift::query()->whereKey($id)->update(['is_active' => $active]) > 0;
    }

    /**
     * 作用：删除某条班次。
     *
     * @param  int  $id  班次主键
     * @return bool 是否真的删掉了记录（id 不存在时为 false）
     */
    public function delete(int $id): bool
    {
        return OpsOnCallShift::query()->whereKey($id)->delete() > 0;
    }

    /**
     * 作用：安全地取当前生效班次的 id，供 list() 标注 current，不让异常冒泡到列表渲染。
     *
     * 为什么单独包一层：list() 是纯展示路径，即便 currentShift() 出错也应照常返回列表（只是不标 current）。
     *
     * @return int|null 当前生效班次 id；无或异常时为 null
     */
    private function safeCurrentShiftId(): ?int
    {
        try {
            return $this->currentShift()?->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 作用：在 SQL 已预筛的绝对生效范围内，进一步按重复模式判断「此刻」是否真正命中该班次。
     *
     * 为什么需要它：SQL 只保证 now 落在 starts_at..ends_at 这个大窗口内，
     * 但 daily/weekly 还要求 now 落在「每天的时段」内、weekly 还要求今天是选中的星期几。
     *
     * @param  OpsOnCallShift  $shift  候选班次
     * @param  CarbonInterface  $now  当前时刻
     * @return bool 此刻是否命中该班次的重复规则
     */
    private function matchesRecurrence(OpsOnCallShift $shift, CarbonInterface $now): bool
    {
        $recurrence = (string) ($shift->recurrence ?? 'once');

        if ($recurrence === 'once') {
            return true; // 绝对窗口已由 SQL 预筛
        }

        // 周期班次：先判断当前 H:i 是否落在每天的时段窗口内，不在则直接不命中。
        if (! $this->timeInWindow($now, (string) $shift->start_time, (string) $shift->end_time)) {
            return false;
        }

        if ($recurrence === 'weekly') {
            $days = array_map('intval', (array) ($shift->days_of_week ?? []));

            // weekly 还需今天（dayOfWeek，0=周日..6=周六）在选中的星期集合里。
            return in_array((int) $now->dayOfWeek, $days, true);
        }

        return true; // daily
    }

    /**
     * 作用：判断当前 H:i 是否落在 [start,end] 时段内，支持跨午夜的时段。
     *
     * 为什么用字符串字典序比较：时间统一格式化为定宽 "H:i"（如 "09:05"），字典序与时间先后一致，
     * 无需解析成分钟即可比较。当 start>end（如 22:00-06:00）视为跨午夜，命中条件改为 t>=start 或 t<=end。
     *
     * @param  CarbonInterface  $now  当前时刻
     * @param  string  $start  时段开始 "H:i"（空串视为未配置）
     * @param  string  $end  时段结束 "H:i"（空串视为未配置）
     * @return bool 当前是否处于该时段内
     */
    private function timeInWindow(CarbonInterface $now, string $start, string $end): bool
    {
        // 时段任一端未配置则一律不命中，防止把空串当边界误判。
        if ($start === '' || $end === '') {
            return false;
        }

        $t = $now->format('H:i');

        return $start <= $end
            ? ($t >= $start && $t <= $end)   // 普通时段：t 同时 >=start 且 <=end
            : ($t >= $start || $t <= $end);  // 跨午夜时段：t 在 start 之后 或 end 之前即算命中
    }

    /**
     * 作用：把班次实体序列化为前端/API 用的数组，并附带 current 标记。
     *
     * @param  OpsOnCallShift  $shift  班次实体
     * @param  int|null  $currentId  当前生效班次 id，用于判定 current
     * @return array<string, mixed> 归一化后的班次数据
     */
    private function serialize(OpsOnCallShift $shift, ?int $currentId): array
    {
        return [
            'id' => $shift->id,
            'assignee' => $shift->assignee,
            'label' => $shift->label,
            'starts_at' => optional($shift->starts_at)->toDateTimeString(),
            'ends_at' => optional($shift->ends_at)->toDateTimeString(),
            'recurrence' => (string) ($shift->recurrence ?? 'once'),
            'days_of_week' => array_map('intval', (array) ($shift->days_of_week ?? [])), // 统一转 int，前端可直接比对
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'is_active' => (bool) $shift->is_active,
            'current' => $shift->id === $currentId, // 是否为此刻正在生效的那条班次
        ];
    }
}
