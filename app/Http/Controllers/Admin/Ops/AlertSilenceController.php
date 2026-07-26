<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertSilenceStoreRequest;
use App\Services\Ops\AlertSilenceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AlertSilenceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AlertSilenceService $silences,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'items' => $this->silences->list(),
            'active' => $this->silences->activeSilences(),
        ]);
    }

    public function store(AlertSilenceStoreRequest $request): JsonResponse
    {
        try {
            $silence = $this->silences->create($request->validated(), $request->user('admin'));
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['ends_at' => [$e->getMessage()]],
            ], 422);
        }

        return $this->success(['silence' => ['id' => $silence->id]]);
    }

    public function update(Request $request, int $silence): JsonResponse
    {
        $updated = $this->silences->toggle($silence, $request->boolean('is_active'));

        return $this->success(['updated' => $updated]);
    }

    public function destroy(int $silence): JsonResponse
    {
        return $this->success(['deleted' => $this->silences->delete($silence)]);
    }
}
