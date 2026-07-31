<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\AlertCenterService;
use App\Services\Ops\MysqlService;
use App\Services\Ops\OctaneControlService;
use App\Services\Ops\RedisService;
use App\Services\Ops\SystemMonitorService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Ops Dashboard API
 *
 * 运维总览面板控制器：作为后台运维中心首页的聚合入口，
 * 一次性汇总系统资源、Redis、MySQL、Octane 与告警中心等多个子系统的概要信息，
 * 供前端一次请求即可渲染整块仪表盘。
 *
 * 为什么：把多个独立监控 Service 收敛到单个端点，避免前端首屏发起多次并行请求，
 * 减少往返并保证各卡片数据在同一时刻快照一致。
 */
class DashboardController extends Controller
{
    // ApiResponse：提供统一的 success()/error() JSON 响应封装
    use ApiResponse;

    /**
     * 作用：聚合各运维子系统的概要数据，返回仪表盘首屏所需的整块 payload。
     *
     * @param  SystemMonitorService  $system  系统资源监控服务（CPU/内存/负载等）
     * @param  RedisService  $redis  Redis 运行状态服务
     * @param  MysqlService  $mysql  MySQL 运行状态服务
     * @param  OctaneControlService  $octane  Octane（Swoole）进程控制/状态服务
     * @param  AlertCenterService  $alerts  告警中心服务
     * @return JsonResponse 统一封装后的成功响应，data 内含五大板块概要
     *
     * 为什么：五个 Service 均通过方法参数注入而非构造函数注入，
     * 这样只有真正命中该 action 时容器才解析这些依赖，避免其他 action 时的无谓构建开销。
     */
    public function index(
        SystemMonitorService $system,
        RedisService $redis,
        MysqlService $mysql,
        OctaneControlService $octane,
        AlertCenterService $alerts,
    ) {
        // 依次委托各子系统 Service 收集概要，并按板块 key 组织为一个聚合结构返回
        return $this->success([
            // 系统资源概要（CPU/内存/负载等）
            'system' => $system->info(),
            // Redis 运行概要
            'redis' => $redis->info(),
            // MySQL 运行概要
            'mysql' => $mysql->info(),
            // Octane 进程状态
            'octane' => $octane->status(),
            // 告警中心汇总
            'alerts' => $alerts->summary(),
        ]);
    }
}
