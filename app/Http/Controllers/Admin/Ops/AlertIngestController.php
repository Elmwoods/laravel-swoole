<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertIngestRequest;
use App\Services\Ops\AlertCenterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 入站 Webhook 告警端点：token 守卫、opt-in、无会话（供外部系统 POST）。注册在 api/ops/ 前缀之外。
 *
 * 作用：对外暴露唯一一条入站告警 Webhook 路由（store），接收第三方监控系统 POST 过来的告警。
 * 「为什么」：
 *   - 无会话：调用方是机器而非登录管理员，故不走 admin 鉴权中间件，改用静态 token 守卫；
 *   - opt-in：整个端点默认关闭，必须在配置里显式开启（ops.alerts.ingest.enabled）才可用；
 *   - 注册在 api/ops/ 前缀之外：避免继承那组前缀上的会话/权限中间件。
 */
class AlertIngestController extends Controller
{
    use ApiResponse;

    /**
     * 作用：校验开关与 token 后，将外部告警载荷落库/合并为一条 OpsAlert 并回执关键字段。
     *
     * @param  AlertIngestRequest  $request  已校验的入站告警载荷（来源、指纹、严重级、标题等）
     * @param  AlertCenterService  $service  方法级注入的告警中心服务，负责入站合并逻辑
     * @return JsonResponse 回执告警 id、fingerprint、status 与 hit_count
     *
     * 「为什么」：先做「开关 -> token」两道门再处理业务，鉴权失败尽早短路，绝不触碰数据。
     */
    public function store(AlertIngestRequest $request, AlertCenterService $service): JsonResponse
    {
        // 第一道门：端点默认关闭；未在配置显式开启则伪装成 404（不暴露端点存在）
        if (! (bool) config('ops.alerts.ingest.enabled', false)) {
            abort(404);
        }

        // 期望 token 来自配置；调用方 token 优先取 Bearer 头，其次取 query 参数
        $expected = (string) config('ops.alerts.ingest.token', '');
        $provided = (string) ($request->bearerToken() ?? $request->query('token', ''));

        // 第二道门：未配置 token（空）视为不可用；用 hash_equals 做恒定时间比较防时序侧信道，失败即 401
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        // 委派 Service 处理外部告警：按 fingerprint 去重合并（已存在则累加 hit_count，否则新建）
        $alert = $service->ingestExternal($request->validated());

        return $this->success([
            'id' => $alert->id,
            'fingerprint' => $alert->fingerprint,
            'status' => $alert->status,
            'hit_count' => (int) $alert->hit_count,
        ]);
    }
}
