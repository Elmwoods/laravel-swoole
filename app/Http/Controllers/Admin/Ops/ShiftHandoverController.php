<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\ShiftHandoverStoreRequest;
use App\Services\Ops\ShiftHandoverService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ShiftHandoverController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShiftHandoverService $handovers) {}

    public function index(): JsonResponse
    {
        return $this->success(['items' => $this->handovers->list()]);
    }

    public function store(ShiftHandoverStoreRequest $request): JsonResponse
    {
        try {
            $handover = $this->handovers->create($request->validated(), $request->user('admin'));
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['to_assignee' => [$e->getMessage()]],
            ], 422);
        }

        return $this->success(['handover' => [
            'id' => $handover->id,
            'from_assignee' => $handover->from_assignee,
            'to_assignee' => $handover->to_assignee,
            'open_alert_count' => (int) $handover->open_alert_count,
        ]]);
    }
}
