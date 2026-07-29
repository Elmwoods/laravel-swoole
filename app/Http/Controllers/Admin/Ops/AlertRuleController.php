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

class AlertRuleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AlertRuleRegistryService $registry,
        private readonly AlertRuleChangeService $changes,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'items' => $this->registry->all()
                ->map(fn (OpsAlertRule $rule): array => $this->serialize($rule))
                ->values()
                ->all(),
        ]);
    }

    public function update(AlertRuleUpdateRequest $request, string $adminRule): JsonResponse
    {
        $rule = $this->findRuleOrFail($adminRule);
        $old = ['warning_threshold' => $rule->warning_threshold, 'critical_threshold' => $rule->critical_threshold, 'is_active' => $rule->is_active];

        $new = [
            'warning_threshold' => (float) $request->validated('warning_threshold'),
            'critical_threshold' => $request->validated('critical_threshold') === null
                ? null
                : (float) $request->validated('critical_threshold'),
            'is_active' => (bool) $request->validated('is_active'),
        ];
        $rule->forceFill($new)->save();
        $this->changes->record($rule->key, $old, $new, $request->user('admin'));

        return $this->success($this->serialize($rule->refresh()));
    }

    public function export(): JsonResponse
    {
        return $this->success($this->registry->export());
    }

    public function import(AlertRuleImportRequest $request): JsonResponse
    {
        return $this->success($this->registry->import($request->validated('rules'), $request->user('admin')));
    }

    public function changes(Request $request): JsonResponse
    {
        return $this->success(['items' => $this->changes->history((string) $request->query('key', '') ?: null)]);
    }

    public function toggle(AlertRuleToggleRequest $request, string $adminRule): JsonResponse
    {
        $rule = $this->findRuleOrFail($adminRule);
        $old = ['is_active' => $rule->is_active];

        $new = ['is_active' => (bool) $request->validated('is_active')];
        $rule->forceFill($new)->save();
        $this->changes->record($rule->key, $old, $new, $request->user('admin'));

        return $this->success($this->serialize($rule->refresh()));
    }

    private function findRuleOrFail(string $key): OpsAlertRule
    {
        $rule = $this->registry->find($key);

        abort_if($rule === null, 404);

        return $rule;
    }

    private function serialize(OpsAlertRule $rule): array
    {
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
