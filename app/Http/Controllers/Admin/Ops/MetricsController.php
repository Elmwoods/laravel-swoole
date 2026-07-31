<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\OpsMetricsExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prometheus 指标导出端点：token 守卫、opt-in、返回原始 text/plain（不走 ApiResponse）。
 * 供外部 Grafana/Prometheus 抓取，无会话认证。
 */
class MetricsController extends Controller
{
    /**
     * 作用：输出 Prometheus 抓取格式的运维指标（text/plain），受配置开关与 token 双重保护。
     *
     * @param  Request  $request  原始 HTTP 请求，用于读取 Bearer / query token
     * @param  OpsMetricsExporter  $exporter  指标导出器，负责把内部指标渲染为 Prometheus 文本格式
     * @return Response 200 时为原始 Prometheus 文本；未开启为 404；鉴权失败为 401
     *
     * 为什么：该端点供外部 Prometheus/Grafana 无会话抓取，不能走后台登录态，
     * 因此改用「配置 opt-in + 静态 token」的独立防护，并直接返回原始文本而非 ApiResponse 的 JSON 包裹。
     */
    public function index(Request $request, OpsMetricsExporter $exporter): Response
    {
        // 权限门槛一：未在配置中显式开启则视为不存在，返回 404（避免暴露端点存在性）
        if (! (bool) config('ops.alerts.metrics.enabled', false)) {
            abort(404);
        }

        // 期望 token 来自配置；调用方 token 优先取 Bearer，其次取 query 参数 token
        $expected = (string) config('ops.alerts.metrics.token', '');
        $provided = (string) ($request->bearerToken() ?? $request->query('token', ''));

        // 权限门槛二：token 未配置或不匹配则 401；hash_equals 做恒定时间比较以防时序侧信道攻击
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        // 渲染并返回 Prometheus 0.0.4 文本格式（不经 ApiResponse，保持抓取器可解析的原始格式）
        return response($exporter->render(), 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
