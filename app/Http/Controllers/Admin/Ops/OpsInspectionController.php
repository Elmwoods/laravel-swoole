<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Models\OpsInspection;
use App\Services\Ops\OpsInspectionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsInspectionController extends Controller
{
    use ApiResponse;

    public function summary(OpsInspectionService $service): JsonResponse
    {
        return $this->success($service->summary());
    }

    public function history(Request $request, OpsInspectionService $service): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $records = OpsInspection::query()
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            'items' => collect($records->items())
                ->map(fn (OpsInspection $record): array => $service->serializeSummary($record))
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

    public function show(OpsInspection $record, OpsInspectionService $service): JsonResponse
    {
        return $this->success($service->serializeDetail($record));
    }

    public function run(Request $request, OpsInspectionService $service): JsonResponse
    {
        $record = $service->run('full', 'manual', $request->user('admin'));

        return $this->success($service->serializeDetail($record));
    }
}
