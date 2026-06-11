<?php

namespace App\Http\Controllers\Admin\Ops\Log;

use App\DTO\Ops\Log\LogQueryDTO;
use App\Http\Controllers\Controller;
use App\Services\Ops\Log\DockerLogService;
use App\Services\Ops\Log\LaravelLogService;
use App\Services\Ops\Log\OctaneLogService;
use App\Services\Ops\Log\RedisLogService;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function laravel(
        Request           $request,
        LaravelLogService $service
    )
    {
        return $this->success(
            $service->latest(
                new LogQueryDTO(
                    lines: $request->integer('lines', 200),
                    keyword: $request->string('keyword')
                )
            )
        );
    }

    public function octane(
        Request          $request,
        OctaneLogService $service
    )
    {
        return $this->success(
            $service->latest(
                new LogQueryDTO(
                    lines: $request->integer('lines', 200)
                )
            )
        );
    }

    public function redis(
        RedisLogService $service
    )
    {
        return $this->success(
            $service->slowLogs()
        );
    }

    public function docker(
        Request          $request,
        DockerLogService $service
    )
    {
        return $this->success(
            $service->latest(
                container: $request->string('container')->toString(),
                lines: $request->integer('lines', 200)
            )
        );
    }
}
