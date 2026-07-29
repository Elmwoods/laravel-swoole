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
    public function index(Request $request, OpsMetricsExporter $exporter): Response
    {
        if (! (bool) config('ops.alerts.metrics.enabled', false)) {
            abort(404);
        }

        $expected = (string) config('ops.alerts.metrics.token', '');
        $provided = (string) ($request->bearerToken() ?? $request->query('token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        return response($exporter->render(), 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
