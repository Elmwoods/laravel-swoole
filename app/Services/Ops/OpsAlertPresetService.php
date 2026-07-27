<?php

namespace App\Services\Ops;

use App\Models\AdminUser;
use App\Models\OpsAlertPreset;

class OpsAlertPresetService
{
    /**
     * 允许存入预设的告警筛选字段（page/per_page 是分页，不入预设）。
     */
    public const ALLOWED_FILTER_KEYS = [
        'status',
        'severity',
        'source',
    ];

    /**
     * 本人预设列表（时间倒序，不跨账号）。
     */
    public function list(AdminUser $admin): array
    {
        return OpsAlertPreset::query()
            ->where('admin_user_id', $admin->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OpsAlertPreset $preset): array => [
                'id' => $preset->id,
                'name' => $preset->name,
                'filters' => (array) $preset->filters,
                'created_at' => optional($preset->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 保存（同名覆盖）一条预设。filters 先按白名单 + 去空清洗，杜绝存任意键。
     */
    public function save(AdminUser $admin, string $name, array $filters): OpsAlertPreset
    {
        return OpsAlertPreset::query()->updateOrCreate(
            ['admin_user_id' => $admin->id, 'name' => $name],
            ['filters' => $this->sanitize($filters)],
        );
    }

    /**
     * 删除本人一条预设（作用域在 WHERE，非 owner id 静默 no-op）。
     */
    public function delete(AdminUser $admin, int $presetId): bool
    {
        return OpsAlertPreset::query()
            ->where('admin_user_id', $admin->id)
            ->whereKey($presetId)
            ->delete() > 0;
    }

    private function sanitize(array $filters): array
    {
        $clean = [];

        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
