<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlertRuleChange;

/**
 * 告警规则变更历史：阈值/启停每次变更的字段级 diff（旧→新 + 操作人）。与 admin.audit 并存（后者记 who/when/result）。
 *
 * 在告警子系统的 raise/notify/broadcast 管线中，本类不参与告警的产生或外发，
 * 而是服务于「规则治理」侧：当运维人员调整某条规则的阈值或启停开关时，
 * 由 AlertRuleRegistryService 调用 record() 落一条审计级别的字段变更记录，
 * 用于事后追溯「是谁、在何时、把哪个阈值从多少改到了多少」。
 * 这些历史进而影响后续 raise 阶段的判定基线（阈值即触发线），但本类自身只做记录与回放。
 */
class AlertRuleChangeService
{
    // 只跟踪用户可调、且会直接影响告警触发的三个字段；其余元数据（name/source 等）不入变更历史。
    private const TRACKED = ['warning_threshold', 'critical_threshold', 'is_active'];

    /**
     * 作用：记录一次规则变更。遍历跟踪字段，只有值真正发生变化的字段才各写一行 diff。
     *
     * 为什么逐字段写行而非整条快照：变更历史以「字段级 diff」呈现，便于前端直接展示
     * 「warning_threshold: 85 → 90」这类可读条目，也避免未改动字段污染历史。
     *
     * @param  string  $ruleKey  规则唯一 key（对应 AlertRuleRegistryService::DEFINITIONS 的键）
     * @param  array<string, mixed>  $old  变更前的字段值快照
     * @param  array<string, mixed>  $new  变更后的字段值快照
     * @param  AdminUser|null  $actor  操作人；为空表示系统自动变更
     */
    public function record(string $ruleKey, array $old, array $new, ?AdminUser $actor = null): void
    {
        $now = now();
        $actorName = $actor?->name ?? 'system'; // 操作人缺省记为 system（如同步默认值/导入时无登录用户）

        foreach (self::TRACKED as $field) {
            // 本次 $new 未提供该字段则跳过——避免把「没改」误判成「改成 null」。
            if (! array_key_exists($field, $new)) {
                continue;
            }

            // 统一 stringify 后再比较：把 bool/数值归一成字符串，规避 true 与 1 之类的伪差异。
            $oldVal = $this->stringify($old[$field] ?? null);
            $newVal = $this->stringify($new[$field] ?? null);

            // 值未变则不落库，历史里只保留真实变更。
            if ($oldVal === $newVal) {
                continue;
            }

            OpsAlertRuleChange::query()->create([
                'rule_key' => $ruleKey,
                'field' => $field,
                'old_value' => $oldVal,
                'new_value' => $newVal,
                'actor' => $actorName,
                'created_at' => $now, // 同一次 record() 的多字段共用同一时间戳，便于按批次归组
            ]);
        }
    }

    /**
     * 作用：回放变更历史，可按 ruleKey 过滤，按主键倒序（最新在前）返回，并序列化为数组给前端。
     *
     * @param  string|null  $ruleKey  规则 key；为 null 或空串时返回全部规则的历史
     * @param  int  $limit  返回条数上限
     * @return array<int, array<string, mixed>> 序列化后的历史条目列表
     */
    public function history(?string $ruleKey = null, int $limit = 50): array
    {
        return OpsAlertRuleChange::query()
            // 仅当 ruleKey 非空时才加过滤条件；null/空串走全量。
            ->when($ruleKey !== null && $ruleKey !== '', fn ($q) => $q->where('rule_key', $ruleKey))
            ->orderByDesc('id')
            // 把外部传入的 limit 夹在 [1,200]：既防 0/负数，也防一次性拉爆库。
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

    /**
     * 作用：把任意字段值归一化成可比较、可存库的字符串（或 null）。
     *
     * 为什么需要：old/new 里可能混有 bool（is_active）、int/float（阈值）、null（无 critical），
     * 统一成字符串后 record() 才能用简单的 === 判等，避免 true==1 之类的类型陷阱。
     *
     * @param  mixed  $value  原始字段值
     * @return string|null 归一化字符串；输入为 null 时保持 null
     */
    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null; // 保留 null 语义（如 critical_threshold 未设置），不转成空串
        }

        if (is_bool($value)) {
            return $value ? '1' : '0'; // bool 统一成 '1'/'0'，与数值型 1/0 对齐可比
        }

        return (string) $value;
    }
}
