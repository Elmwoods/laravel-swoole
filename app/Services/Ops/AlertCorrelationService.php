<?php

namespace App\Services\Ops;

use App\Models\OpsAlert;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 告警关联抑制：按 config 的父子来源依赖，父来源有 open 告警时抑制子来源告警外发。
 *
 * 在 raise/notify/broadcast 管线中，本类作用于 notify（通知外发）这一环的「上游因果」判定：
 * 当一个根因来源（父，如 mysql）已经 firing 时，由它派生的下游来源（子，如 queue）告警
 * 属于连带噪音，应被抑制，避免风暴式重复打扰值班。抑制只压「外发到通道」，不影响入库/广播，
 * 因此下游告警仍可见、仍可复盘，只是不再重复叫醒人。
 *
 * boot-safe：任何异常/未配置都返回 false（不抑制），绝不因关联逻辑漏掉真实告警。
 */
class AlertCorrelationService
{
    /**
     * 作用：构建依赖拓扑图——把 config 里的父子来源依赖，叠加各来源当前 open 状态，拼成 nodes + edges 供前端可视化。
     *
     * 为什么自查 open 计数而不复用 AlertCenterService：避免服务间循环依赖（AlertCenter 会反向用到本类），此处直接查库最省事。
     *
     * @return array{enabled:bool, nodes:array<int, array<string, mixed>>, edges:array<int, array{from:string, to:string}>}
     */
    public function topology(): array
    {
        $enabled = (bool) config('ops.alerts.correlation.enabled', false); // 开关：读 config，缺省关闭
        $deps = (array) config('ops.alerts.correlation.dependencies', []); // 依赖表：child => [parents...]

        // 收集所有来源（child keys ∪ parent values）。用关联数组的键天然去重。
        $sources = [];
        $edges = [];
        foreach ($deps as $child => $parents) {
            $child = (string) $child;
            $sources[$child] = true;
            foreach ((array) $parents as $parent) {
                $parent = (string) $parent;
                // 跳过空父与自环（父==子）：无意义的边会让拓扑图出现自指，也会误伤抑制判定。
                if ($parent === '' || $parent === $child) {
                    continue;
                }
                $sources[$parent] = true;
                $edges[] = ['from' => $parent, 'to' => $child]; // 边方向：父指向子（因 → 果）
            }
        }

        // 各来源当前 open 计数（自查，不注入 AlertCenterService 防环）。
        $openCounts = [];
        try {
            $openCounts = OpsAlert::query()
                ->where('status', 'open')
                // array_keys 为空时用 [''] 兜底，防止 whereIn 收到空数组产生非法 SQL。
                ->whereIn('source', array_keys($sources) ?: [''])
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->pluck('total', 'source')
                ->all();
        } catch (Throwable) {
            $openCounts = []; // boot-safe：表缺失/查询异常时按「无 open」处理，拓扑仍可渲染
        }

        $nodes = [];
        foreach (array_keys($sources) as $source) {
            $open = (int) ($openCounts[$source] ?? 0);
            $nodes[] = [
                'source' => $source,
                'open' => $open,
                'firing' => $open > 0, // 有 open 告警即视为该来源正在 firing
                // suppressed：自身在 firing，且它有父来源也在 firing —— 说明本节点是被上游连带抑制的下游噪音。
                'suppressed' => $open > 0 && $this->firingParents($source) !== [],
            ];
        }

        return ['enabled' => $enabled, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * 作用：判断某条告警此刻是否应被上游根因抑制（notify 环的 choke point 会调用它决定是否外发）。
     *
     * 为什么先查开关再查父：开关关闭时直接放行，零 DB 开销；只有开启且确有父来源时才落到 exists 查询。
     *
     * @param  OpsAlert  $alert  待判定的告警
     * @return bool true=被抑制（父来源有 open 告警，本告警不外发）；false=不抑制
     */
    public function isSuppressed(OpsAlert $alert): bool
    {
        try {
            // 关联抑制未启用则一律不抑制。
            if (! (bool) config('ops.alerts.correlation.enabled', false)) {
                return false;
            }

            $parents = $this->parentsOf((string) $alert->source);

            // 无父依赖的来源本身就是根因，不可能被抑制。
            if ($parents === []) {
                return false;
            }

            // 只要任一父来源当前有 open 告警，就抑制本告警——exists 短路，不需精确计数。
            return OpsAlert::query()
                ->whereIn('source', $parents)
                ->where('status', 'open')
                ->exists();
        } catch (Throwable) {
            return false; // boot-safe：出错时不抑制，宁可多发也不漏真实告警
        }
    }

    /**
     * 作用：返回某子来源当前正在 firing（有 open 告警）的父来源列表，供 UI 高亮与抑制事件的原因说明。
     *
     * 为什么单列此方法：isSuppressed 只需 bool 结论；而拓扑图/通知说明需要「具体是哪些父在冒烟」，故单独精确取出。
     *
     * @param  string  $source  子来源标识
     * @return array<int, string> 正在 firing 的父来源（去重、按 source 排序）；未启用/无父/出错均返回空数组
     */
    public function firingParents(string $source): array
    {
        try {
            // 未启用关联时无所谓父子 firing，直接空。
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
                ->distinct() // 同一父可能有多条 open 告警，去重成来源名单
                ->orderBy('source')
                ->pluck('source')
                ->all();
        } catch (Throwable) {
            return []; // boot-safe：出错按「无 firing 父」处理
        }
    }

    /**
     * 作用：从 config 依赖表中取出某来源的父来源列表，并过滤掉非法项。
     *
     * 为什么要过滤：config 由人工维护，可能混入非字符串、空串或自指（父==子）；这里统一清洗，
     * 让上层（isSuppressed/firingParents）拿到的父列表始终干净可用。
     *
     * @param  string  $source  子来源标识
     * @return array<int, string> 合法父来源列表（重建连续下标）
     */
    private function parentsOf(string $source): array
    {
        $map = (array) config('ops.alerts.correlation.dependencies', []); // 读依赖配置，缺省空表
        $parents = array_values(array_filter(
            (array) ($map[$source] ?? []),
            // 只保留非空、非自指的字符串父来源。
            fn ($p): bool => is_string($p) && $p !== '' && $p !== $source,
        ));

        return $parents;
    }
}
