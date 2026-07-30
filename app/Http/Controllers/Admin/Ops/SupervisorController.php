<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\SupervisorControlRequest;
use App\Http\Requests\Admin\Ops\SupervisorProcessRequest;
use App\Services\Ops\SupervisorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SupervisorController extends Controller
{
    use ApiResponse;

    public function status(
        SupervisorService $service
    ): JsonResponse {
        return $this->success(
            $service->status()
        );
    }

    public function start(
        SupervisorProcessRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        return $this->success(
            $service->start($request->serviceName())
        );
    }

    public function stop(
        SupervisorControlRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        return $this->success(
            $service->stop($request->serviceName())
        );
    }

    public function restart(
        SupervisorControlRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        return $this->success(
            $service->restart($request->serviceName())
        );
    }

    public function reread(
        SupervisorService $service
    ): JsonResponse {
        return $this->success([
            'result' => $service->reread(),
        ]);
    }

    public function update(
        SupervisorService $service
    ): JsonResponse {
        return $this->success([
            'result' => $service->update(),
        ]);
    }

    public function tail(
        SupervisorProcessRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        return $this->success([
            'logs' => $service->tail($request->serviceName()),
        ]);
    }

    public function logs(
        SupervisorProcessRequest $request,
        SupervisorService $service,
    ): JsonResponse {
        $name = $request->serviceName();

        return $this->success([
            'service' => $name,
            'logs' => $service->logs($name),
        ]);
    }
}
