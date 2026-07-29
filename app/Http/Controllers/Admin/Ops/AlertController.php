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
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ops Center 告警中心控制器。
 *
 * Controller 保持薄层：入参验证交给 Request，业务逻辑交给 Service。
 */
class AlertController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AlertCenterService $service,
    ) {}

    /**
     * 告警列表。
     */
    public function index(AlertIndexRequest $request): JsonResponse
    {
        $paginator = $this->service->paginate($request->validated());

        return $this->success([
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
     */
    public function summary(): JsonResponse
    {
        return $this->success($this->service->summary());
    }

    /**
     * 已被指派过的处理人列表（供筛选下拉）。
     */
    public function assignees(): JsonResponse
    {
        return $this->success(['items' => $this->service->assignees()]);
    }

    /**
     * 批量确认某分组的 open 告警。
     */
    public function batchAcknowledge(AlertBatchRequest $request): JsonResponse
    {
        return $this->success($this->service->batchByGroup(
            $request->validated('by'),
            $request->validated('group'),
            'acknowledge',
            ['note' => $request->validated('note')],
        ));
    }

    /**
     * 批量指派某分组的 open 告警。
     */
    public function batchAssign(AlertBatchRequest $request): JsonResponse
    {
        abort_if($request->validated('assigned_to') === null, 422, '批量指派需要 assigned_to。');

        return $this->success($this->service->batchByGroup(
            $request->validated('by'),
            $request->validated('group'),
            'assign',
            ['assigned_to' => $request->validated('assigned_to'), 'note' => $request->validated('note')],
        ));
    }

    /**
     * 为某分组创建静默窗口。
     */
    public function batchSilence(AlertBatchRequest $request): JsonResponse
    {
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
     */
    public function groups(Request $request): JsonResponse
    {
        $by = (string) $request->query('by', 'source');
        $by = in_array($by, ['source', 'severity', 'assigned_to'], true) ? $by : 'source';

        return $this->success([
            'by' => $by,
            'groups' => $this->service->groupedOpen($by),
        ]);
    }

    /**
     * 通知通道配置状态。
     */
    public function notificationStatus(): JsonResponse
    {
        return $this->success($this->service->notificationStatus());
    }

    public function settings(): JsonResponse
    {
        return $this->success($this->service->settings());
    }

    public function updateSettings(AlertSettingsUpdateRequest $request): JsonResponse
    {
        return $this->success($this->service->updateSettings($request->validated()));
    }

    public function latestEvaluation(): JsonResponse
    {
        return $this->success($this->service->latestEvaluation());
    }

    /**
     * 告警评估趋势（近 N 天按天聚合）。
     */
    public function trend(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 14)));

        return $this->success([
            'days' => $days,
            'buckets' => $this->service->evaluationTrend($days),
        ]);
    }

    /**
     * 告警处理 SLA 统计（MTTA/MTTR + 按来源/严重级 + 趋势 + 积压分桶）。
     */
    public function sla(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 30)));

        return $this->success([
            'days' => $days,
            ...$this->service->slaSummary($days),
        ]);
    }

    /**
     * 告警热力图（小时×星期频率 + 最吵来源 + 趋势）。
     */
    public function heatmap(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 30)));
        $weighted = $request->boolean('weighted');

        return $this->success([
            'days' => $days,
            ...$this->service->heatmapSummary($days, $weighted),
        ]);
    }

    /**
     * 值班绩效统计（按处理人：确认/恢复数 + 平均响应时长）。
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
     */
    public function report(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', (int) config('ops.alerts.weekly_report.window_days', 7))));

        return $this->success($this->service->weeklyReportSummary($days));
    }

    /**
     * 手动触发一次告警评估。
     */
    public function evaluate(): JsonResponse
    {
        return $this->success($this->service->evaluate());
    }

    /**
     * 测试 Telegram / 邮件通知配置。
     */
    public function testNotification(AlertNotificationTestRequest $request): JsonResponse
    {
        return $this->success($this->service->testNotification($request->validated()));
    }

    /**
     * 立即对已启用通道做一次连通性自检，返回更新后的通知状态。
     */
    public function runHealthCheck(AlertChannelHealthService $health): JsonResponse
    {
        $summary = $health->run();

        return $this->success([
            'summary' => $summary,
            ...$this->service->notificationStatus(),
        ]);
    }

    /**
     * 生成告警中心演示数据。
     */
    public function demoScenarios(): JsonResponse
    {
        return $this->success($this->service->demoScenarios());
    }

    /**
     * 确认告警。
     */
    public function acknowledge(AlertAcknowledgeRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            $this->service->serialize(
                $this->service->acknowledge($alert, $request->validated()),
            ),
        );
    }

    public function assign(AlertAssignRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            $this->service->serialize(
                $this->service->assign($alert, $request->validated()),
            ),
        );
    }

    public function setTags(AlertTagsRequest $request, OpsAlert $alert): JsonResponse
    {
        return $this->success(
            $this->service->serialize(
                $this->service->setTags($alert, (array) $request->validated('tags')),
            ),
        );
    }

    /**
     * 相似告警：同来源历史已恢复告警 + 当时的处理备注。
     */
    public function similar(OpsAlert $alert): JsonResponse
    {
        return $this->success(['items' => $this->service->similarAlerts($alert)]);
    }

    /**
     * 标记告警已恢复。
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
