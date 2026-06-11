<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OctaneControlService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class OctaneController extends Controller
{
    use ApiResponse;

    public function status(OctaneControlService $service): JsonResponse
    {
        return $this->success($service->status());
    }

    public function reload(OctaneControlService $service): JsonResponse
    {
        return $this->success([
            'reloaded' => $service->reload()
        ]);
    }

    public function restart(OctaneControlService $service): JsonResponse
    {
        return $this->success([
            'restarted' => $service->restart()
        ]);
    }

    public function stop(OctaneControlService $service): JsonResponse
    {
        return $this->success([
            'stopped' => $service->stop()
        ]);
    }
}
