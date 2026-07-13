<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\MysqlService;
use App\Services\Ops\OctaneControlService;
use App\Services\Ops\RedisService;
use App\Services\Ops\SystemMonitorService;
use App\Traits\ApiResponse;

/**
 * Ops Dashboard API
 */
class DashboardController extends Controller
{
    use ApiResponse;

    public function index(
        SystemMonitorService $system,
        RedisService $redis,
        MysqlService $mysql,
        OctaneControlService $octane,
        AlertCenterService $alerts,
    ) {
        return $this->success([
            'system' => $system->info(),
            'redis' => $redis->info(),
            'mysql' => $mysql->info(),
            'octane' => $octane->status(),
            'alerts' => $alerts->summary(),
        ]);
    }
}
