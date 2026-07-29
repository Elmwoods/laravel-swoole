<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertIngestRequest;
use App\Services\Ops\AlertCenterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 入站 Webhook 告警端点：token 守卫、opt-in、无会话（供外部系统 POST）。注册在 api/ops/ 前缀之外。
 */
class AlertIngestController extends Controller
{
    use ApiResponse;

    public function store(AlertIngestRequest $request, AlertCenterService $service): JsonResponse
    {
        if (! (bool) config('ops.alerts.ingest.enabled', false)) {
            abort(404);
        }

        $expected = (string) config('ops.alerts.ingest.token', '');
        $provided = (string) ($request->bearerToken() ?? $request->query('token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        $alert = $service->ingestExternal($request->validated());

        return $this->success([
            'id' => $alert->id,
            'fingerprint' => $alert->fingerprint,
            'status' => $alert->status,
            'hit_count' => (int) $alert->hit_count,
        ]);
    }
}
