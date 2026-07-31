<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use App\Models\OpsOnCallShift;
use Illuminate\Support\Facades\Schema;

/**
 * 值班仪表盘：把当前值班、未来班次、待处理告警与 SLA 快照聚成一屏，供交接班一眼看清。纯只读聚合。
 *
 * 在告警子系统（raise 告警 -> notify 通知 -> broadcast 广播）中的定位：
 *  - 本类不参与 raise/notify/broadcast 的写入路径，是这三者的「读侧回看」——
 *    把「谁在岗（rotation）」与「告警/SLA 现状（AlertCenterService）」交叉呈现，
 *    让当班/接班的人知道自己名下有多少 open 告警、有多少无人认领、SLA 是否吃紧。
 *  - current_on_call 来自 OnCallRotationService（即 raise 阶段用来自动派单的同一个「找人」结果），
 *    因此仪表盘上「我的告警」正是当前值班人会被 notify 的那批告警。
 *  - 全只读聚合，不改任何状态，可安全地在页面高频刷新。
 */
class OnCallDashboardService
{
    /**
     * 作用：注入排班服务与告警中心服务，仪表盘的两类数据分别来自它们。
     *
     * @param  OnCallRotationService  $rotation  提供「当前值班人 / 未来班次」
     * @param  AlertCenterService  $alerts  提供 SLA 汇总等告警统计
     */
    public function __construct(
        private readonly OnCallRotationService $rotation,
        private readonly AlertCenterService $alerts,
    ) {}

    /**
     * 作用：组装值班总览，一次性返回交接班需要看的全部信息。
     *
     * @param  int  $days  SLA 统计的回看窗口天数（会被夹到 1..90）
     * @return array<string, mixed> 含当前值班、未来班次、我的/无人认领 open 告警、SLA 快照
     */
    public function overview(int $days = 7): array
    {
        // 天数夹取到 [1,90]：下限 1 避免空窗口，上限 90 防止过大范围拖慢统计查询。
        $days = max(1, min(90, $days));
        // 当前值班人：与 raise 阶段自动派单用的是同一来源，保证「我的告警」口径一致。
        $current = $this->rotation->currentOnCall();

        return [
            'generated_at' => now()->toDateTimeString(),
            'current_on_call' => $current,
            'upcoming_shifts' => $this->upcomingShifts(),
            'my_open_alerts' => $this->assignedOpen($current),   // 以当前值班人为口径统计其名下 open 告警
            'unassigned_open' => $this->unassignedOpen(),
            'sla' => $this->slaSnapshot($days),
        ];
    }

    /**
     * 作用：取未来即将生效的班次（起始时间还没到、按开始时间升序取最近 5 条），用于「接下来谁值班」预览。
     *
     * @return array<int, array<string, mixed>> 未来班次数组（表缺失时为空数组）
     */
    private function upcomingShifts(): array
    {
        // boot-safe 守卫：表不存在时返回空数组，不影响仪表盘其它区块。
        if (! Schema::hasTable('ops_on_call_shifts')) {
            return [];
        }

        return OpsOnCallShift::query()
            ->where('is_active', true)
            ->where('starts_at', '>', now())  // 仅「尚未开始」的班次才算未来班次
            ->orderBy('starts_at')             // 越早开始的越靠前
            ->limit(5)                         // 只预览最近 5 条，避免列表过长
            ->get()
            ->map(fn (OpsOnCallShift $shift): array => [
                'id' => $shift->id,
                'assignee' => $shift->assignee,
                'label' => $shift->label,
                'starts_at' => optional($shift->starts_at)->toDateTimeString(),
                'ends_at' => optional($shift->ends_at)->toDateTimeString(),
                'recurrence' => (string) ($shift->recurrence ?? 'once'),
            ])
            ->all();
    }

    /**
     * 作用：统计指定处理人名下的 open 告警总数及按严重级（critical/warning/info）的分解。
     *
     * 为什么用闭包 $base + clone：每个 count 都要在「同一基础条件」上再叠加不同 severity 过滤；
     * 闭包保证条件集中定义，clone 保证复用查询构造器时互不污染（Builder 是有状态的）。
     *
     * @param  string|null  $assignee  处理人标识（通常即当前值班人）；为 null 表示无人在岗
     * @return array<string, mixed> 含 assignee 与 total/critical/warning/info 计数
     */
    private function assignedOpen(?string $assignee): array
    {
        // 基础查询：status=open 且指派给该处理人；用闭包封装以便下面按 severity 复用。
        $base = fn () => OpsAlert::query()->where('status', 'open')->where('assigned_to', $assignee);

        // 边界：无人在岗或告警表缺失时，直接返回全 0（既省查询又保持结构一致，便于前端渲染）。
        if ($assignee === null || ! Schema::hasTable('ops_alerts')) {
            return ['assignee' => $assignee, 'total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0];
        }

        return [
            'assignee' => $assignee,
            'total' => $base()->count(),
            // 对每个严重级 clone 一份基础查询再加过滤，避免在同一 Builder 上叠加条件相互干扰。
            'critical' => (clone $base())->where('severity', 'critical')->count(),
            'warning' => (clone $base())->where('severity', 'warning')->count(),
            'info' => (clone $base())->where('severity', 'info')->count(),
        ];
    }

    /**
     * 作用：统计当前无人认领（assigned_to 为空）的 open 告警数量——需要有人主动接手的积压。
     *
     * @return int 无人认领的 open 告警数（表缺失时为 0）
     */
    private function unassignedOpen(): int
    {
        // boot-safe 守卫：表不存在返回 0。
        if (! Schema::hasTable('ops_alerts')) {
            return 0;
        }

        return OpsAlert::query()->where('status', 'open')->whereNull('assigned_to')->count();
    }

    /**
     * 作用：从告警中心的 SLA 汇总里裁出仪表盘要用的几项（积压分桶 + 达标率 + 当前违约数）。
     *
     * 为什么大量用 ?? 兜底：slaSummary 的返回结构可能因数据缺失而少键，
     * 逐层 ?? 保证即使上游缺字段，仪表盘也返回可渲染的默认值而非报错。
     *
     * @param  int  $days  统计窗口天数
     * @return array<string, mixed> 含 window_days/open_aging/ack_rate/resolve_rate/open_breaches
     */
    private function slaSnapshot(int $days): array
    {
        $sla = $this->alerts->slaSummary($days);

        return [
            'window_days' => $days,
            // 积压分桶缺失时给出三桶全 0 的默认结构，保证前端字段稳定。
            'open_aging' => $sla['open_aging'] ?? ['under_1h' => 0, 'one_to_24h' => 0, 'over_24h' => 0],
            'ack_rate' => $sla['compliance']['ack']['overall']['rate'] ?? null,          // 总体确认达标率（可能无数据为 null）
            'resolve_rate' => $sla['compliance']['resolve']['overall']['rate'] ?? null,  // 总体解决达标率
            'open_breaches' => $sla['open_breaches'] ?? 0,                               // 当前已违约的 open 告警数
        ];
    }
}
