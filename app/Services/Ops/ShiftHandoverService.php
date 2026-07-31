<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsShiftHandover;
use InvalidArgumentException;

/**
 * 值班交接班记录：值班切换时记一条交出/接手 + 备注 + 当时 open 告警快照，形成值班日志。全局记录。
 *
 * 在告警 raise/通知 notify/广播 broadcast 流水线中的定位：
 * - 本类不直接参与 raise（探测生成告警）或 notify/broadcast（对外发送）；
 * - 它处在流水线的"下游消费侧"：当值班人轮换时，把交接那一刻的未处理（open）告警总数快照下来，
 *   连同交出人/接手人/备注一起落库，形成可追溯的值班日志。
 * - 依赖 OnCallRotationService 推断当前值班人，依赖 AlertCenterService 读取 open 告警统计，
 *   两者都是流水线上其它环节维护的状态，此处只做只读消费与归档。
 * 「为什么」要快照 open_alert_count：交接是责任移交的时间点，
 *   记录当时的待处理告警数，便于事后复盘"这批告警是在谁的班上遗留/产生的"。
 */
class ShiftHandoverService
{
    public function __construct(
        private readonly OnCallRotationService $onCall,   // 值班轮值服务：用于推断当前应当接手的值班人
        private readonly AlertCenterService $alerts,      // 告警中心服务：用于读取当前 open 告警总数快照
    ) {}

    /**
     * 作用：创建一条值班交接记录（交出人、接手人、备注、open 告警快照）。
     *
     * @param  array  $data  交接输入，可含 to_assignee / from_assignee / note，均可缺省由系统推断
     * @param  AdminUser|null  $actor  执行本次交接操作的管理员（记录为 created_by，可为 null）
     * @return OpsShiftHandover 新建的交接记录模型
     *
     * @throws InvalidArgumentException 当既未显式给出接手人、又无法从轮值推断出当前值班人时抛出
     */
    public function create(array $data, ?AdminUser $actor = null): OpsShiftHandover
    {
        // 接手人：优先取显式传入的 to_assignee（非空白）；缺省时回退到轮值服务推断的当前值班人
        $to = isset($data['to_assignee']) && trim((string) $data['to_assignee']) !== ''
            ? trim((string) $data['to_assignee'])
            : $this->onCall->currentOnCall();

        // 边界校验：既没显式接手人、轮值也推断不出（无排班）时，交接对象无从谈起，直接拒绝而非落一条无接手人记录
        if ($to === null || $to === '') {
            throw new InvalidArgumentException('无法确定接手人：当前无值班人，请显式填写接手人。');
        }

        // 交出人：优先取显式传入的 from_assignee；缺省时回退到上一条交接记录的接手人（即上一班的接手人=本次的交出人）
        $from = isset($data['from_assignee']) && trim((string) $data['from_assignee']) !== ''
            ? trim((string) $data['from_assignee'])
            : optional(OpsShiftHandover::query()->latest('id')->first())->to_assignee; // optional() 兜底首次交接（无历史记录）返回 null

        return OpsShiftHandover::query()->create([
            'from_assignee' => $from,
            'to_assignee' => $to,
            // 备注为空白时统一存 null，避免存入无意义的空串
            'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
            // 快照交接时刻的未处理告警总数；summary() 无该键时兜底为 0，保证字段始终有整型值
            'open_alert_count' => (int) ($this->alerts->summary()['open_total'] ?? 0),
            'created_by' => $actor?->id, // 记录操作者；匿名/系统触发时 $actor 为 null，则不署名
        ]);
    }

    /**
     * 作用：返回最近的值班交接记录列表（最多 100 条，按 id 倒序，最新在前）。
     *
     * @return array<int, array<string, mixed>> 交接记录数组，每项含 id/from_assignee/to_assignee/note/open_alert_count/created_at
     *
     * 「为什么」限制 100 条：交接日志仅供近期复盘，硬上限避免全表扫描拖慢接口。
     */
    public function list(): array
    {
        return OpsShiftHandover::query()
            ->orderByDesc('id')
            ->limit(100) // 只取最近 100 条，控制返回体量与查询开销
            ->get()
            ->map(fn (OpsShiftHandover $h): array => [
                'id' => $h->id,
                'from_assignee' => $h->from_assignee,
                'to_assignee' => $h->to_assignee,
                'note' => $h->note,
                'open_alert_count' => (int) $h->open_alert_count,
                // optional() 兜底：created_at 可能为 null，避免直接调用格式化方法报错
                'created_at' => optional($h->created_at)->toDateTimeString(),
            ])
            ->all();
    }
}
