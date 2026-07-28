<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\OnCallShiftStoreRequest;
use App\Services\Ops\OnCallRotationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class OnCallController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OnCallRotationService $rotation,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'items' => $this->rotation->list(),
            'current' => $this->rotation->currentOnCall(),
        ]);
    }

    public function store(OnCallShiftStoreRequest $request): JsonResponse
    {
        try {
            $shift = $this->rotation->create($request->validated(), $request->user('admin'));
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['ends_at' => [$e->getMessage()]],
            ], 422);
        }

        return $this->success(['shift' => ['id' => $shift->id]]);
    }

    public function update(Request $request, int $shift): JsonResponse
    {
        return $this->success(['updated' => $this->rotation->toggle($shift, $request->boolean('is_active'))]);
    }

    public function destroy(int $shift): JsonResponse
    {
        return $this->success(['deleted' => $this->rotation->delete($shift)]);
    }
}
