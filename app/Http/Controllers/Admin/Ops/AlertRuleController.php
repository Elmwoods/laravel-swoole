<?php

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Ops\AlertRuleImportRequest;
use App\Http\Requests\Admin\Ops\AlertRuleToggleRequest;
use App\Http\Requests\Admin\Ops\AlertRuleUpdateRequest;
use App\Models\OpsAlertRule;
use App\Services\Ops\AlertRuleChangeService;
use App\Services\Ops\AlertRuleRegistryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 告警规则控制器。
 *
 * 作用：承载告警规则(OpsAlertRule) 的管理路由——列出规则、改阈值/启停、导入/导出、
 *   切换启用状态，以及查询规则变更历史。路由通常位于 ops/alerts/rules 之下。
 * 「为什么」：规则的阈值(warning/critical)决定告警何时触发，属敏感配置；
 *   每次写入都同时记录一条变更历史(AlertRuleChangeService)，形成可审计的改动轨迹。
 */
class AlertRuleController extends Controller
{
    use ApiResponse;

    /**
     * 作用：注入规则注册表服务与规则变更历史服务。
     *
     * @param  AlertRuleRegistryService  $registry  规则的读取/查找/导入导出与定义元数据来源
     * @param  AlertRuleChangeService  $changes  记录并查询规则变更历史（旧值->新值）
     * @return void
     */
    public function __construct(
        private readonly AlertRuleRegistryService $registry,
        private readonly AlertRuleChangeService $changes,
    ) {}

    /**
     * 作用：列出全部告警规则（含定义元数据如取值上下限）。
     *
     * @return JsonResponse 含 items 序列化后的规则数组
     */
    public function index(): JsonResponse
    {
        return $this->success([
            // 取全部规则并逐条 serialize（合并注册表里的 min/max/requires_critical 等定义元数据）
            'items' => $this->registry->all()
                ->map(fn (OpsAlertRule $rule): array => $this->serialize($rule))
                ->values()
                ->all(),
        ]);
    }

    /**
     * 作用：更新单条规则的告警阈值与启用状态，并记录一条变更历史。
     *
     * @param  AlertRuleUpdateRequest  $request  已校验的 warning_threshold / critical_threshold（可空）/ is_active
     * @param  string  $adminRule  规则唯一 key（来自路由参数），据此定位规则
     * @return JsonResponse 序列化后的更新态规则
     *
     * 「为什么」：先快照旧值再写新值，然后把 (旧值,新值,操作人) 交给变更服务，保证改动可追溯回滚。
     */
    public function update(AlertRuleUpdateRequest $request, string $adminRule): JsonResponse
    {
        $rule = $this->findRuleOrFail($adminRule);
        // 写前快照旧值，用于变更历史 diff
        $old = ['warning_threshold' => $rule->warning_threshold, 'critical_threshold' => $rule->critical_threshold, 'is_active' => $rule->is_active];

        $new = [
            'warning_threshold' => (float) $request->validated('warning_threshold'),
            // critical 阈值允许留空（表示不设严重级门限），非空才转 float
            'critical_threshold' => $request->validated('critical_threshold') === null
                ? null
                : (float) $request->validated('critical_threshold'),
            'is_active' => (bool) $request->validated('is_active'),
        ];
        // forceFill 绕过 fillable 白名单直写这几列，随即落库
        $rule->forceFill($new)->save();
        // 记录变更历史：key + 旧值 + 新值 + 操作管理员
        $this->changes->record($rule->key, $old, $new, $request->user('admin'));

        return $this->success($this->serialize($rule->refresh()));
    }

    /**
     * 作用：导出全部规则配置（供备份或跨环境迁移）。
     *
     * @return JsonResponse 可再被 import 消费的规则导出结构
     */
    public function export(): JsonResponse
    {
        return $this->success($this->registry->export());
    }

    /**
     * 作用：批量导入规则配置，返回导入结果。
     *
     * @param  AlertRuleImportRequest  $request  已校验的 rules 规则数组，并携带操作管理员
     * @return JsonResponse 导入结果（新增/更新/跳过等统计）
     *
     * 「为什么」：传入操作管理员，便于 Service 内对导入产生的每处改动一并记账/审计。
     */
    public function import(AlertRuleImportRequest $request): JsonResponse
    {
        return $this->success($this->registry->import($request->validated('rules'), $request->user('admin')));
    }

    /**
     * 作用：查询规则变更历史；可按规则 key 过滤，不传则返回全部。
     *
     * @param  Request  $request  查询参数 key（规则唯一键，可空）
     * @return JsonResponse 含 items 变更历史数组
     *
     * 「为什么」：`?: null` 把空字符串归一为 null，让 Service 区分「按某 key 过滤」与「全量」。
     */
    public function changes(Request $request): JsonResponse
    {
        // 空 key 归一为 null：等价于不按 key 过滤，返回全部历史
        return $this->success(['items' => $this->changes->history((string) $request->query('key', '') ?: null)]);
    }

    /**
     * 作用：仅切换单条规则的启用/停用状态，并记录变更历史。
     *
     * @param  AlertRuleToggleRequest  $request  已校验的 is_active 布尔
     * @param  string  $adminRule  规则唯一 key（来自路由参数）
     * @return JsonResponse 序列化后的更新态规则
     *
     * 「为什么」：相较 update 只动 is_active 一列，是列表页快速启停的轻量入口，同样入账变更历史。
     */
    public function toggle(AlertRuleToggleRequest $request, string $adminRule): JsonResponse
    {
        $rule = $this->findRuleOrFail($adminRule);
        // 写前快照旧的启用状态
        $old = ['is_active' => $rule->is_active];

        $new = ['is_active' => (bool) $request->validated('is_active')];
        $rule->forceFill($new)->save();
        // 记录启停变更历史
        $this->changes->record($rule->key, $old, $new, $request->user('admin'));

        return $this->success($this->serialize($rule->refresh()));
    }

    /**
     * 作用：按 key 定位规则，找不到则抛 404。
     *
     * @param  string  $key  规则唯一键
     * @return OpsAlertRule 命中的规则模型
     *
     * 「为什么」：抽出私有辅助，让 update/toggle 复用「查不到即 404」的一致失败语义。
     */
    private function findRuleOrFail(string $key): OpsAlertRule
    {
        $rule = $this->registry->find($key);

        // 未命中该 key 的规则则中断为 404
        abort_if($rule === null, 404);

        return $rule;
    }

    /**
     * 作用：把规则模型序列化为前端结构，并合并注册表中的定义元数据。
     *
     * @param  OpsAlertRule  $rule  待序列化的规则模型
     * @return array 含阈值、单位、启用态及定义元数据（min/max/requires_critical）的数组
     *
     * 「为什么」：min/max/requires_critical 等是代码内置的规则「定义」而非库内可改字段，
     *   序列化时从注册表 definition 补齐，供前端做输入校验与 UI 约束。
     */
    private function serialize(OpsAlertRule $rule): array
    {
        // 取该规则的静态定义元数据（内置约束）；无定义时兜底空数组
        $definition = $this->registry->definition($rule->key) ?? [];

        return [
            'id' => $rule->id,
            'key' => $rule->key,
            'name' => $rule->name,
            'source' => $rule->source,
            'metric' => $rule->metric,
            'operator' => $rule->operator,
            'warning_threshold' => $rule->warning_threshold,
            'critical_threshold' => $rule->critical_threshold,
            'unit' => $rule->unit,
            'is_active' => $rule->is_active,
            'description' => $rule->description,
            'sort_order' => $rule->sort_order,
            'min' => $definition['min'] ?? 0,
            'max' => $definition['max'] ?? 1000000,
            'requires_critical' => $definition['requires_critical'] ?? false,
            'updated_at' => optional($rule->updated_at)->toDateTimeString(),
        ];
    }
}
