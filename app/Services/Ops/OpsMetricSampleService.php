<?php

namespace App\Services\Ops;

use App\Models\OpsMetricSample;

/**
 * 系统指标采样持久化与趋势聚合。
 */
class OpsMetricSampleService
{
    /**
     * 从 SystemMetricsCollector::collect() 的快照派生便于趋势的标量指标。
     */
    public function deriveMetrics(array $snapshot): array
    {
        $load = (array) ($snapshot['load'] ?? []);
        $memory = (array) ($snapshot['memory'] ?? []);
        $swap = (array) ($snapshot['swap'] ?? []);

        return [
            'cpu_load' => round((float) ($snapshot['cpu'] ?? ($load[0] ?? 0)), 2),
            'load1' => round((float) ($load[0] ?? 0), 2),
            'memory_used_percent' => $this->usedPercent(
                (float) ($memory['total'] ?? 0),
                (float) ($memory['available'] ?? 0),
            ),
            'swap_used_percent' => $this->usedPercent(
                (float) ($swap['total'] ?? 0),
                (float) ($swap['free'] ?? 0),
            ),
        ];
    }

    public function record(array $snapshot): OpsMetricSample
    {
        return OpsMetricSample::query()->create(array_merge(
            $this->deriveMetrics($snapshot),
            ['captured_at' => now()],
        ));
    }

    /**
     * 回填 N 天的 demo 系统指标样本（每天 6 个点，确定性曲线），用于本地/演示环境观察趋势。
     *
     * 返回写入条数。
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

                OpsMetricSample::query()->create([
                    'cpu_load' => round(1.0 + 2.0 * (0.5 + 0.5 * sin($phase)), 2),
                    'load1' => round(0.8 + 2.2 * (0.5 + 0.5 * sin($phase + 0.3)), 2),
                    'memory_used_percent' => round(45 + 25 * (0.5 + 0.5 * cos($d / 2.0)), 2),
                    'swap_used_percent' => round(max(0, 6 * (0.5 + 0.5 * sin($d))), 2),
                    'captured_at' => $at,
                ]);
                $seeded++;
            }
        }

        return $seeded;
    }

    /**
     * 按天聚合近 $days 天的系统指标平均值（只返回有数据的天，升序）。
     */
    public function trend(int $days): array
    {
        $days = max(1, min(90, $days));
        $since = now()->startOfDay()->subDays($days - 1);

        return OpsMetricSample::query()
            ->where('captured_at', '>=', $since)
            ->selectRaw('DATE(captured_at) as date')
            ->selectRaw('AVG(cpu_load) as cpu_load')
            ->selectRaw('AVG(load1) as load1')
            ->selectRaw('AVG(memory_used_percent) as memory_used_percent')
            ->selectRaw('AVG(swap_used_percent) as swap_used_percent')
            ->groupByRaw('DATE(captured_at)')
            ->orderByRaw('DATE(captured_at)')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->date,
                'cpu_load' => round((float) $row->cpu_load, 2),
                'load1' => round((float) $row->load1, 2),
                'memory_used_percent' => round((float) $row->memory_used_percent, 2),
                'swap_used_percent' => round((float) $row->swap_used_percent, 2),
            ])
            ->all();
    }

    private function usedPercent(float $total, float $available): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(max(0, min(100, ($total - $available) / $total * 100)), 2);
    }
}
