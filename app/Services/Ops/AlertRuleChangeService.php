<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlertRuleChange;

/**
 * 告警规则变更历史：阈值/启停每次变更的字段级 diff（旧→新 + 操作人）。与 admin.audit 并存（后者记 who/when/result）。
 */
class AlertRuleChangeService
{
    private const TRACKED = ['warning_threshold', 'critical_threshold', 'is_active'];

    /**
     * 记录一次规则变更：遍历跟踪字段，值有变化才写一行。
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(string $ruleKey, array $old, array $new, ?AdminUser $actor = null): void
    {
        $now = now();
        $actorName = $actor?->name ?? 'system';

        foreach (self::TRACKED as $field) {
            if (! array_key_exists($field, $new)) {
                continue;
            }

            $oldVal = $this->stringify($old[$field] ?? null);
            $newVal = $this->stringify($new[$field] ?? null);

            if ($oldVal === $newVal) {
                continue;
            }

            OpsAlertRuleChange::query()->create([
                'rule_key' => $ruleKey,
                'field' => $field,
                'old_value' => $oldVal,
                'new_value' => $newVal,
                'actor' => $actorName,
                'created_at' => $now,
            ]);
        }
    }

    public function history(?string $ruleKey = null, int $limit = 50): array
    {
        return OpsAlertRuleChange::query()
            ->when($ruleKey !== null && $ruleKey !== '', fn ($q) => $q->where('rule_key', $ruleKey))
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (OpsAlertRuleChange $c): array => [
                'id' => $c->id,
                'rule_key' => $c->rule_key,
                'field' => $c->field,
                'old_value' => $c->old_value,
                'new_value' => $c->new_value,
                'actor' => $c->actor,
                'created_at' => optional($c->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
