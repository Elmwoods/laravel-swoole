<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Models\OpsReleaseCheck;
use App\Services\Ops\OpsReleaseCheckHistoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsReleaseCheckController extends Controller
{
    use ApiResponse;

    public function overview(OpsReleaseCheckHistoryService $service): JsonResponse
    {
        return $this->success($service->overview());
    }

    public function run(Request $request, OpsReleaseCheckHistoryService $service): JsonResponse
    {
        $record = $service->runAndRecord($request->user('admin'));

        return $this->success($service->serializeDetail($record));
    }

    public function history(Request $request, OpsReleaseCheckHistoryService $service): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $records = OpsReleaseCheck::query()
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            'items' => collect($records->items())
                ->map(fn (OpsReleaseCheck $record): array => $service->serializeSummary($record))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    public function show(OpsReleaseCheck $record, OpsReleaseCheckHistoryService $service): JsonResponse
    {
        return $this->success($service->serializeDetail($record));
    }
}
