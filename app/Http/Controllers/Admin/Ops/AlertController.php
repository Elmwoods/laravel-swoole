<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertAcknowledgeRequest;
use App\Http\Requests\Admin\Ops\AlertIndexRequest;
use App\Http\Requests\Admin\Ops\AlertNotificationTestRequest;
use App\Models\OpsAlert;
use App\Services\Ops\AlertCenterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

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
}
