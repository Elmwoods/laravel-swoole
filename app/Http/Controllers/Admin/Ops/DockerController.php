<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\DockerContainerRequest;
use App\Http\Requests\Admin\Ops\DockerControlRequest;
use App\Services\Ops\Docker\DockerService;

class DockerController extends Controller
{
    public function __construct(
        protected DockerService $docker
    ) {
    }

    /**
     * 容器列表
     */
    public function containers()
    {
        return $this->success($this->docker->containers());
    }

    /**
     * 容器汇总
     */
    public function summary()
    {
        return $this->success($this->docker->summary());
    }

    /**
     * 容器资源统计
     */
    public function stats(DockerContainerRequest $request)
    {
        return $this->success(
            $this->docker->stats($request->containerId())
        );
    }

    /**
     * 容器日志
     */
    public function logs(DockerContainerRequest $request)
    {
        return $this->success(
            $this->docker->logs($request->containerId())
        );
    }

    public function restart(DockerControlRequest $request)
    {
        return $this->success(
            $this->docker->restart($request->containerId())
        );
    }

    public function stop(DockerControlRequest $request)
    {
        return $this->success(
            $this->docker->stop($request->containerId())
        );
    }

    public function start(DockerContainerRequest $request)
    {
        return $this->success(
            $this->docker->start($request->containerId())
        );
    }

    public function version()
    {
        return $this->success($this->docker->version());
    }
}
