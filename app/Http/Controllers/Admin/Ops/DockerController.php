<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\DockerService;

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
     * 容器资源统计
     */
    public function stats(string $id)
    {
        return $this->success($this->docker->stats($id));
    }

    /**
     * 容器日志
     */
    public function logs(string $id)
    {
        return $this->success($this->docker->logs($id));
    }

    public function restart(string $id)
    {
        return$this->success($this->docker->restart($id));
    }

    public function stop(string $id)
    {
        return$this->success($this->docker->stop($id));
    }

    public function start(string $id)
    {
        return$this->success($this->docker->start($id));
    }
}
