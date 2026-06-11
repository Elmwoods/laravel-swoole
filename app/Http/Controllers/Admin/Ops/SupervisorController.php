<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\SupervisorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SupervisorController extends Controller
{
    use ApiResponse;

    public function status(
        SupervisorService $service
    ): JsonResponse
    {
        return $this->success(
            $service->status()
        );
    }

    public function start(
        SupervisorService $service,
        string $name
    ): JsonResponse
    {
        return $this->success(
            $service->start($name)
        );
    }

    public function stop(
        SupervisorService $service,
        string $name
    ): JsonResponse
    {
        return $this->success(
            $service->stop($name)
        );
    }

    public function restart(
        SupervisorService $service,
        string $name
    ): JsonResponse
    {
        return $this->success(
            $service->restart($name)
        );
    }

    public function reread(
        SupervisorService $service
    ): JsonResponse
    {
        return $this->success([
            'result' => $service->reread()
        ]);
    }

    public function update(
        SupervisorService $service
    ): JsonResponse
    {
        return $this->success([
            'result' => $service->update()
        ]);
    }

    public function tail(
        SupervisorService $service,
        string $name
    ): JsonResponse
    {
        return $this->success([
            'logs' => $service->tail($name)
        ]);
    }

    public function logs(
        SupervisorService $service,
        string $name
    ): JsonResponse {

        return $this->success([
            'service' => $name,
            'logs' => $service->logs($name)
        ]);
    }
}
