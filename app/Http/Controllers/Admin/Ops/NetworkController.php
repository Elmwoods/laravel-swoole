<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\NetworkTrafficService;
use Illuminate\Http\JsonResponse;

/**
 * 网络流量监控 Controller
 *
 * 作用：为运维中心提供主机实时网络吞吐（上/下行速率）的查询入口，
 * 具体的网卡采样与速率计算全部委托给 NetworkTrafficService。
 *
 * 为什么：Controller 只承担 HTTP 层职责，采样两次并做差值/时间归一化得到瞬时速率的逻辑放在 Service 中，便于复用与测试。
 */
class NetworkController extends Controller
{
    /**
     * 作用：构造函数，注入网络流量服务。
     *
     * @param  NetworkTrafficService  $service  网络流量采样与速率计算服务（提升为受保护属性）
     */
    public function __construct(
        protected NetworkTrafficService $service
    ) {}

    /**
     * 获取实时网络流量
     *
     * 作用：返回当前网络的实时上/下行速率。
     *
     * @return JsonResponse 统一封装的实时网络速率数据
     */
    public function index(): JsonResponse
    {
        // 委托 Service 采样并计算瞬时网络速率
        return $this->success($this->service->getSpeed());
    }
}
