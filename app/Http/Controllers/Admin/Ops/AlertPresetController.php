<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertPresetStoreRequest;
use App\Services\Ops\OpsAlertPresetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 告警中心筛选预设（owner-scoped）。审计由路由的 admin.audit:ops.alerts,preset_* 中间件记录。
 */
class AlertPresetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OpsAlertPresetService $presets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success([
            'items' => $this->presets->list($request->user('admin')),
        ]);
    }

    public function store(AlertPresetStoreRequest $request): JsonResponse
    {
        $preset = $this->presets->save(
            $request->user('admin'),
            (string) $request->validated('name'),
            (array) $request->validated('filters', []),
        );

        return $this->success([
            'preset' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'filters' => (array) $preset->filters,
                'created_at' => optional($preset->created_at)->toDateTimeString(),
            ],
        ]);
    }

    public function destroy(Request $request, int $preset): JsonResponse
    {
        return $this->success([
            'deleted' => $this->presets->delete($request->user('admin'), $preset),
        ]);
    }
}
