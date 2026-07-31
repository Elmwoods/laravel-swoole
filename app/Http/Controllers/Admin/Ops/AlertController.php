<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertAcknowledgeRequest;
use App\Http\Requests\Admin\Ops\AlertAssignRequest;
use App\Http\Requests\Admin\Ops\AlertBatchRequest;
use App\Http\Requests\Admin\Ops\AlertIndexRequest;
use App\Http\Requests\Admin\Ops\AlertNotificationTestRequest;
use App\Http\Requests\Admin\Ops\AlertSettingsUpdateRequest;
use App\Http\Requests\Admin\Ops\AlertTagsRequest;
use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\AlertChannelHealthService;
use App\Services\Ops\AlertCorrelationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ops Center 告警中心控制器。
 *
 * Controller 保持薄层：入参验证交给 Request，业务逻辑交给 Service。
 *
 * 作用：承载运维告警中心（Ops Center Alert）的全部读写 HTTP 路由——
 *   列表/汇总/分组/趋势/热力图/SLA/值班/周报等只读统计接口，
 *   以及确认(ack)/指派(assign)/恢复(resolve)/打标签/批量操作/静默/评估触发等写操作。
 *   对应路由前缀通常为 admin api 下的 ops/alerts。
 * 「为什么」：所有真实逻辑下沉到 AlertCenterService / AlertCorrelationService，
 *   Controller 只做「验证 -> 委派 Service -> 统一 JSON 包装」三件事，便于测试与复用。
 */
class AlertController extends Controller
{
    use ApiResponse;

    /**
     * 作用：注入告警中心所需的领域服务（构造函数依赖注入）。
     *
     * @param  AlertCenterService  $service  告警中心核心服务：分页、序列化、ack/resolve、统计等
     * @param  AlertCorrelationService  $correlation  告警关联/依赖拓扑服务（服务依赖图与抑制状态）
     * @return void
     *
     * 「为什么」：用 readonly 私有属性持有依赖，保证控制器无状态、线程/协程安全。
     */
    public function __construct(
        private readonly AlertCenterService $service,
        private readonly AlertCorrelationService $correlation,
    ) {}

    /**
     * 告警列表。
     *
     * 作用：按筛选条件分页返回告警，并把每条 OpsAlert 序列化为前端所需结构。
     *
     * @param  AlertIndexRequest  $request  已通过校验的列表筛选参数（状态/来源/严重级/处理人/分页等）
     * @return JsonResponse 含 items（序列化后的告警数组）与 pagination（分页元信息）
     */
    public function index(AlertIndexRequest $request): JsonResponse
    {
        // 委派 Service 执行带筛选的分页查询，返回 Laravel 分页器
        $paginator = $this->service->paginate($request->validated());

        return $this->success([
            // 逐条调用 Service::serialize 做字段裁剪/格式化，再取值重排索引
            'items' => collect($paginator->items())
                ->map(fn (OpsAlert $alert): array => $this->service->serialize($alert))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 告警汇总。
     *
     * 作用：返回告警计数概览（如各状态/严重级数量），用于顶部指标卡。
     *
     * @return JsonResponse Service::summary() 计算出的汇总结构
     */
    public function summary(): JsonResponse
    {
        return $this->success($this->service->summary());
    }

    /**
     * 已被指派过的处理人列表（供筛选下拉）。
     *
     * 作用：返回历史上出现过的 assigned_to 取值集合，前端用作筛选下拉选项。
     *
     * @return JsonResponse 含 items 处理人数组
     */
    public function assignees(): JsonResponse
    {
        return $this->success(['items' => $this->service->assignees()]);
    }

    /**
     * 批量确认某分组的 open 告警。
     *
     * 作用：对某一聚合分组（by=source/severity/...）下的全部 open 告警一次性执行确认(ack)。
     *
     * @param  AlertBatchRequest  $request  批量操作参数：by（分组维度）、group（分组值）、note（备注）
     * @return JsonResponse Service 返回的受影响条数等结果
     *
     * 「为什么」：值班时同一来源可能瞬间爆出大量告警，按分组批量 ack 避免逐条点击。
     */
    public function batchAcknowledge(AlertBatchRequest $request): JsonResponse
    {
        // 委派 Service 按分组批量执行 'acknowledge' 动作，附带处理备注
        return $this->success($this->service->batchByGroup(
            $request->validated('by'),
            $request->validated('group'),
            'acknowledge',
            ['note' => $request->validated('note')],
        ));
    }

    /**
     * 批量指派某分组的 open 告警。
     *
     * 作用：把某分组下所有 open 告警一次性指派给同一处理人。
     *
     * @param  AlertBatchRequest  $request  批量参数：by、group、assigned_to（必填）、note
     * @return JsonResponse Service 返回的受影响结果
     *
     * 「为什么」：指派动作必须有目标处理人，故此处显式兜底校验 assigned_to 非空。
     */
    public function batchAssign(AlertBatchRequest $request): JsonResponse
    {
        // 指派必须给出目标人；缺失则直接 422 中断，避免把告警指派给 null
        abort_if($request->validated('assigned_to') === null, 422, '批量指派需要 assigned_to。');

        // 委派 Service 按分组批量执行 'assign' 动作
        return $this->success($this->service->batchByGroup(
            $request->validated('by'),
            $request->validated('group'),
            'assign',
            ['assigned_to' => $request->validated('assigned_to'), 'note' => $request->validated('note')],
        ));
    }

    /**
     * 为某分组创建静默窗口。
     *
     * 作用：对某聚合分组批量建立一个静默(silence)时间窗，窗口内匹配告警不再触发通知。
     *
     * @param  AlertBatchRequest  $request  批量参数：by、group、minutes（窗口时长，缺省 60 分钟）
     * @return JsonResponse 含新建静默 id 与结束时间 ends_at
     *
     * 「为什么」：处理已知故障期间需临时压制刷屏告警，静默窗口到期自动失效。
     */
    public function batchSilence(AlertBatchRequest $request): JsonResponse
    {
        // 委派 Service 为该分组创建静默窗口；未传时长时默认 60 分钟，并记录操作管理员
        $silence = $this->service->batchSilenceGroup(
            $request->validated('by'),
            $request->validated('group'),
            (int) ($request->validated('minutes') ?? 60),
            $request->user('admin'),
        );

        return $this->success(['silence_id' => $silence->id, 'ends_at' => optional($silence->ends_at)->toDateTimeString()]);
    }

    /**
     * open 告警分组聚合（by source|severity|assigned_to）。
     *
     * 作用：把当前 open 告警按指定维度聚合计数，供分组视图展示。
     *
     * @param  Request  $request  查询参数 by：分组维度，仅允许 source/severity/assigned_to
     * @return JsonResponse 含 by（最终生效维度）与 groups（各分组统计）
     *
     * 「为什么」：白名单校验 by，非法取值一律回退为 source，防止任意字段被用于分组注入。
     */
    public function groups(Request $request): JsonResponse
    {
        $by = (string) $request->query('by', 'source');
        // 仅接受三种合法维度，其余一律回退 source（白名单防注入）
        $by = in_array($by, ['source', 'severity', 'assigned_to'], true) ? $by : 'source';

        return $this->success([
            'by' => $by,
            'groups' => $this->service->groupedOpen($by),
        ]);
    }

    /**
     * 通知通道配置状态。
     *
     * 作用：返回各通知通道（Telegram/邮件等）的启用与健康状态。
     *
     * @return JsonResponse Service::notificationStatus() 的通道状态结构
     */
    public function notificationStatus(): JsonResponse
    {
        return $this->success($this->service->notificationStatus());
    }

    /**
     * 作用：读取告警中心的全局设置（阈值默认值、通知开关等）。
     *
     * @return JsonResponse 当前设置
     */
    public function settings(): JsonResponse
    {
        return $this->success($this->service->settings());
    }

    /**
     * 作用：更新告警中心全局设置。
     *
     * @param  AlertSettingsUpdateRequest  $request  已校验的设置字段
     * @return JsonResponse 更新后的设置
     */
    public function updateSettings(AlertSettingsUpdateRequest $request): JsonResponse
    {
        return $this->success($this->service->updateSettings($request->validated()));
    }

    /**
     * 作用：返回最近一次告警评估(evaluation)的结果快照。
     *
     * @return JsonResponse 最近一次评估结果
     */
    public function latestEvaluation(): JsonResponse
    {
        return $this->success($this->service->latestEvaluation());
    }

    /**
     * 告警评估趋势（近 N 天按天聚合）。
     *
     * 作用：返回最近 days 天内告警评估结果按天聚合的时间序列。
     *
     * @param  Request  $request  查询参数 days（默认 14），被夹取到 1~90
     * @return JsonResponse 含 days 与 buckets（按天分桶数据）
     *
     * 「为什么」：min/max 双重夹取防止用户传入 0 或超大天数拖垮聚合查询。
     */
    public function trend(Request $request): JsonResponse
    {
        // days 夹取到 [1,90]，避免非法/过大区间
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            'buckets' => $this->service->evaluationTrend($days),
        ]);
    }

    /**
     * 告警处理 SLA 统计（MTTA/MTTR + 按来源/严重级 + 趋势 + 积压分桶）。
     *
     * 作用：计算平均确认时长(MTTA)、平均恢复时长(MTTR)等 SLA 指标。
     *
     * @param  Request  $request  查询参数 days（默认 30），夹取到 1~90
     * @return JsonResponse 含 days 及 slaSummary 展开的各项指标
     *
     * 「为什么」：用 ... 展开把 Service 返回的多项指标平铺进响应，避免多一层嵌套。
     */
    public function sla(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 30)));

        return $this->success([
            'days' => $days,
            // 展开合并 SLA 汇总各字段到同一层级
            ...$this->service->slaSummary($days),
        ]);
    }

    /**
     * 告警热力图（小时×星期频率 + 最吵来源 + 趋势）。
     *
     * 作用：统计告警在「小时 × 星期」二维网格上的分布频率。
     *
     * @param  Request  $request  查询参数 days（默认 30，夹取 1~90）、weighted（是否按严重级加权）
     * @return JsonResponse 含 days 及 heatmapSummary 展开的热力图数据
     */
    public function heatmap(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 30)));
        // weighted=true 时按严重级加权计数，否则按条数计数
        $weighted = $request->boolean('weighted');

        return $this->success([
            'days' => $days,
            ...$this->service->heatmapSummary($days, $weighted),
        ]);
    }

    /**
     * 服务依赖拓扑（父子来源依赖 + 各节点当前 firing/suppressed 状态）。
     *
     * 作用：返回服务依赖关系图，标注每个节点当前是否 firing（触发中）或 suppressed（被抑制）。
     *
     * @return JsonResponse AlertCorrelationService::topology() 生成的拓扑图
     *
     * 「为什么」：此接口委派给关联服务而非核心服务，因为依赖抑制属于告警关联域。
     */
    public function topology(): JsonResponse
    {
        // 依赖拓扑由 AlertCorrelationService（关联域）负责
        return $this->success($this->correlation->topology());
    }

    /**
     * 值班绩效统计（按处理人：确认/恢复数 + 平均响应时长）。
     *
     * 作用：按处理人聚合其确认数、恢复数与平均响应时长，衡量值班工作量。
     *
     * @param  Request  $request  查询参数 days（默认 30，夹取 1~90）
     * @return JsonResponse 含 days 及 workloadSummary 展开的按人统计
     */
    public function workload(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 30)));

        return $this->success([
            'days' => $days,
            ...$this->service->workloadSummary($days),
        ]);
    }

    /**
     * 告警统计周报（告警 + SLA + 值班）。
     *
     * 作用：生成综合周报，汇总告警量、SLA 指标与值班绩效。
     *
     * @param  Request  $request  查询参数 days，缺省取配置 ops.alerts.weekly_report.window_days（默认 7），夹取 1~90
     * @return JsonResponse weeklyReportSummary 生成的周报数据
     *
     * 「为什么」：默认窗口读配置而非硬编码，方便按部署环境调整周报跨度。
     */
    public function report(Request $request): JsonResponse
    {
        // 未传 days 时回落到配置的周报窗口天数
        $days = min(90, max(1, (int) $request->integer('days', (int) config('ops.alerts.weekly_report.window_days', 7))));

        return $this->success($this->service->weeklyReportSummary($days));
    }

    /**
     * 手动触发一次告警评估。
     *
     * 作用：立即跑一遍规则评估管线（正常由调度定时触发），返回本次评估结果。
     *
     * @return JsonResponse 本次评估结果
     *
     * 「为什么」：便于调试或改完阈值后立刻验证，无需等待下一个调度周期。
     */
    public function evaluate(): JsonResponse
    {
        return $this->success($this->service->evaluate());
    }

    /**
     * 测试 Telegram / 邮件通知配置。
     *
     * 作用：按提交的通道配置发送一条测试通知，验证连通性与凭据是否正确。
     *
     * @param  AlertNotificationTestRequest  $request  已校验的通道类型与目标参数
     * @return JsonResponse 发送测试结果
     */
    public function testNotification(AlertNotificationTestRequest $request): JsonResponse
    {
        return $this->success($this->service->testNotification($request->validated()));
    }

    /**
     * 立即对已启用通道做一次连通性自检，返回更新后的通知状态。
     *
     * 作用：主动跑一遍所有已启用通道的健康检查，并把最新通知状态一并返回。
     *
     * @param  AlertChannelHealthService  $health  方法级注入的通道健康自检服务
     * @return JsonResponse 含 summary（本次自检汇总）与展开后的最新通知状态
     *
     * 「为什么」：AlertChannelHealthService 仅此处需要，采用方法参数注入而非构造注入以减少默认依赖。
     */
    public function runHealthCheck(AlertChannelHealthService $health): JsonResponse
    {
        // 执行一次全通道连通性自检
        $summary = $health->run();

        return $this->success([
            'summary' => $summary,
            // 自检可能改变通道健康标记，故重新取一次通知状态平铺返回
            ...$this->service->notificationStatus(),
        ]);
    }

    /**
     * 生成告警中心演示数据。
     *
     * 作用：造一批演示用告警场景数据，便于前端联调或演示。
     *
     * @return JsonResponse 生成的演示数据结果
     */
    public function demoScenarios(): JsonResponse
    {
        return $this->success($this->service->demoScenarios());
    }

    /**
     * 确认告警。
     *
     * 作用：把单条告警标记为已确认(acknowledged)，记录确认人与备注，并返回序列化后的最新告警。
     *
     * @param  AlertAcknowledgeRequest  $request  已校验的确认参数（如 by、note）
     * @param  OpsAlert  $alert  路由模型绑定注入的目标告警
     * @return JsonResponse 序列化后的更新态告警
     *
     * 「为什么」：ack 是告警处理流程第一步（open -> acknowledged），用于「已收到、处理中」的态度确认。
     */
    public function acknowledge(AlertAcknowledgeRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            // 先 acknowledge 落库改状态，再 serialize 成前端结构
            $this->service->serialize(
                $this->service->acknowledge($alert, $request->validated()),
            ),
        );
    }

    /**
     * 作用：把单条告警指派给指定处理人，返回序列化后的最新告警。
     *
     * @param  AlertAssignRequest  $request  已校验的指派参数（assigned_to 等）
     * @param  OpsAlert  $alert  路由模型绑定注入的目标告警
     * @return JsonResponse 序列化后的更新态告警
     */
    public function assign(AlertAssignRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            $this->service->serialize(
                $this->service->assign($alert, $request->validated()),
            ),
        );
    }

    /**
     * 作用：覆盖设置单条告警的标签集合，返回序列化后的最新告警。
     *
     * @param  AlertTagsRequest  $request  已校验的 tags 数组
     * @param  OpsAlert  $alert  路由模型绑定注入的目标告警
     * @return JsonResponse 序列化后的更新态告警
     *
     * 「为什么」：setTags 为整体覆盖语义（非增量），前端需提交完整标签列表。
     */
    public function setTags(AlertTagsRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            $this->service->serialize(
                // 强转为数组兜底，防止 tags 缺省时传入 null
                $this->service->setTags($alert, (array) $request->validated('tags')),
            ),
        );
    }

    /**
     * 相似告警：同来源历史已恢复告警 + 当时的处理备注。
     *
     * 作用：为当前告警检索同来源、历史上已恢复的相似告警及其处理备注，供快速借鉴处置经验。
     *
     * @param  OpsAlert  $alert  路由模型绑定注入的当前告警
     * @return JsonResponse 含 items 相似告警数组
     */
    public function similar(OpsAlert $alert): JsonResponse
    {
        return $this->success(['items' => $this->service->similarAlerts($alert)]);
    }

    /**
     * 标记告警已恢复。
     *
     * 作用：把单条告警置为已恢复(resolved)状态，记录恢复人/备注，返回序列化后的最新告警。
     *
     * @param  AlertAcknowledgeRequest  $request  已校验参数（复用确认请求：by、note）
     * @param  OpsAlert  $alert  路由模型绑定注入的目标告警
     * @return JsonResponse 序列化后的更新态告警
     *
     * 「为什么」：resolve 是处理流程终点（-> resolved），复用 AlertAcknowledgeRequest 是因字段一致（无需单独请求类）。
     */
    public function resolve(AlertAcknowledgeRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            $this->service->serialize(
                $this->service->resolve($alert, $request->validated()),
            ),
        );
    }
}
