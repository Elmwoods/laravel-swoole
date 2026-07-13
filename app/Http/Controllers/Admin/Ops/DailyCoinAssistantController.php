<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\DailyCoinAutomationRequest;
use App\Http\Requests\Admin\Ops\DailyCoinConfirmRequest;
use App\Services\Ops\DailyCoinAssistantService;
use Illuminate\Http\JsonResponse;

class DailyCoinAssistantController extends Controller
{
    public function __construct(
        private readonly DailyCoinAssistantService $service,
    ) {}

    public function summary(): JsonResponse
    {
        return $this->success($this->service->summary());
    }

    public function reminder(): JsonResponse
    {
        return $this->success($this->service->markReminder());
    }

    public function confirm(DailyCoinConfirmRequest $request): JsonResponse
    {
        return $this->success($this->service->confirm($request->validated()));
    }

    public function automationRequest(DailyCoinAutomationRequest $request): JsonResponse
    {
        if ($request->validated('mode') === 'private_token') {
            return response()->json([
                'code' => 422,
                'message' => '不支持私有 token、抓包复用或绕过风控的自动化方式。',
                'data' => null,
                'timestamp' => now()->timestamp,
            ], 422);
        }

        return $this->success([
            'mode' => $request->validated('mode'),
            'accepted' => true,
        ]);
    }
}
