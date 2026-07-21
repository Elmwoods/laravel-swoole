<?php

namespace App\Services\Ops;

use App\Models\OpsRedisMetricSample;

/**
 * Redis 指标采样持久化与趋势聚合（镜像 OpsMetricSampleService）。
 */
class OpsRedisMetricSampleService
{
    /**
     * 从 RedisMetricsService::collect() + 命中率快照派生标量。
     */
    public function deriveMetrics(array $snapshot): array
    {
        return [
            'ops' => max(0, (int) ($snapshot['ops'] ?? 0)),
            'clients' => max(0, (int) ($snapshot['clients'] ?? 0)),
            'memory_mb' => round(max(0, (float) ($snapshot['memory'] ?? 0)) / 1048576, 2),
            'hit_rate' => round(max(0, min(100, (float) ($snapshot['hit_rate'] ?? 0))), 2),
        ];
    }

    public function record(array $snapshot): OpsRedisMetricSample
    {
        return OpsRedisMetricSample::query()->create(array_merge(
            $this->deriveMetrics($snapshot),
            ['captured_at' => now()],
        ));
    }

    /**
     * 按天聚合近 $days 天 Redis 指标平均值（只返回有数据的天，升序）。
     */
    public function trend(int $days): array
    {
        $days = max(1, min(90, $days));
        $since = now()->startOfDay()->subDays($days - 1);

        return OpsRedisMetricSample::query()
            ->where('captured_at', '>=', $since)
            ->selectRaw('DATE(captured_at) as date')
            ->selectRaw('AVG(ops) as ops')
            ->selectRaw('AVG(clients) as clients')
            ->selectRaw('AVG(memory_mb) as memory_mb')
            ->selectRaw('AVG(hit_rate) as hit_rate')
            ->groupByRaw('DATE(captured_at)')
            ->orderByRaw('DATE(captured_at)')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->date,
                'ops' => round((float) $row->ops, 2),
                'clients' => round((float) $row->clients, 2),
                'memory_mb' => round((float) $row->memory_mb, 2),
                'hit_rate' => round((float) $row->hit_rate, 2),
            ])
            ->all();
    }

    /**
     * 回填 N 天 demo 样本（确定性曲线），仅供本地/演示观察趋势。
     */
    public function seedDemo(int $days): int
    {
        $days = max(1, min(90, $days));
        $now = now();
        $seeded = 0;

        for ($d = $days - 1; $d >= 0; $d--) {
            for ($h = 0; $h < 6; $h++) {
                $at = $now->copy()->subDays($d)->setTime(2 + $h * 3, 0, 0);
                $phase = ($d * 6 + $h) / 4.0;

                OpsRedisMetricSample::query()->create([
                    'ops' => (int) round(200 + 300 * (0.5 + 0.5 * sin($phase))),
                    'clients' => (int) round(10 + 20 * (0.5 + 0.5 * sin($phase + 0.5))),
                    'memory_mb' => round(20 + 30 * (0.5 + 0.5 * cos($d / 2.0)), 2),
                    'hit_rate' => round(85 + 14 * (0.5 + 0.5 * sin($d)), 2),
                    'captured_at' => $at,
                ]);
                $seeded++;
            }
        }

        return $seeded;
    }
}
