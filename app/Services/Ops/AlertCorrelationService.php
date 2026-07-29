<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 告警关联抑制：按 config 的父子来源依赖，父来源有 open 告警时抑制子来源告警外发。
 *
 * boot-safe：任何异常/未配置都返回 false（不抑制），绝不因关联逻辑漏掉真实告警。
 */
class AlertCorrelationService
{
    /**
     * 依赖拓扑：把 config 父子来源依赖 + 各来源当前 open 状态拼成图（nodes + edges）。只读、boot-safe。
     */
    public function topology(): array
    {
        $enabled = (bool) config('ops.alerts.correlation.enabled', false);
        $deps = (array) config('ops.alerts.correlation.dependencies', []);

        // 收集所有来源（child keys ∪ parent values）。
        $sources = [];
        $edges = [];
        foreach ($deps as $child => $parents) {
            $child = (string) $child;
            $sources[$child] = true;
            foreach ((array) $parents as $parent) {
                $parent = (string) $parent;
                if ($parent === '' || $parent === $child) {
                    continue;
                }
                $sources[$parent] = true;
                $edges[] = ['from' => $parent, 'to' => $child];
            }
        }

        // 各来源当前 open 计数（自查，不注入 AlertCenterService 防环）。
        $openCounts = [];
        try {
            $openCounts = OpsAlert::query()
                ->where('status', 'open')
                ->whereIn('source', array_keys($sources) ?: [''])
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->pluck('total', 'source')
                ->all();
        } catch (Throwable) {
            $openCounts = [];
        }

        $nodes = [];
        foreach (array_keys($sources) as $source) {
            $open = (int) ($openCounts[$source] ?? 0);
            $nodes[] = [
                'source' => $source,
                'open' => $open,
                'firing' => $open > 0,
                'suppressed' => $open > 0 && $this->firingParents($source) !== [],
            ];
        }

        return ['enabled' => $enabled, 'nodes' => $nodes, 'edges' => $edges];
    }

    public function isSuppressed(OpsAlert $alert): bool
    {
        try {
            if (! (bool) config('ops.alerts.correlation.enabled', false)) {
                return false;
            }

            $parents = $this->parentsOf((string) $alert->source);

            if ($parents === []) {
                return false;
            }

            return OpsAlert::query()
                ->whereIn('source', $parents)
                ->where('status', 'open')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 某子来源当前正在 firing 的父来源列表（供 UI/事件说明）。
     *
     * @return array<int, string>
     */
    public function firingParents(string $source): array
    {
        try {
            if (! (bool) config('ops.alerts.correlation.enabled', false)) {
                return [];
            }

            $parents = $this->parentsOf($source);

            if ($parents === []) {
                return [];
            }

            return OpsAlert::query()
                ->whereIn('source', $parents)
                ->where('status', 'open')
                ->distinct()
                ->orderBy('source')
                ->pluck('source')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function parentsOf(string $source): array
    {
        $map = (array) config('ops.alerts.correlation.dependencies', []);
        $parents = array_values(array_filter(
            (array) ($map[$source] ?? []),
            fn ($p): bool => is_string($p) && $p !== '' && $p !== $source,
        ));

        return $parents;
    }
}
