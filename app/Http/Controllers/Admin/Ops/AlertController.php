<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertAcknowledgeRequest;
use App\Http\Requests\Admin\Ops\AlertAssignRequest;
use App\Http\Requests\Admin\Ops\AlertIndexRequest;
use App\Http\Requests\Admin\Ops\AlertNotificationTestRequest;
use App\Http\Requests\Admin\Ops\AlertSettingsUpdateRequest;
use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
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
