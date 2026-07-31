<?php

namespace App\Services\Admin;

use App\Models\AdminAuditPreset;
use App\Models\AdminUser;

/**
 * 后台审计日志「筛选预设」服务。
 *
 * 属于后台审计子系统的辅助端：让管理员把一组常用的审计日志筛选条件（模块、动作、
 * 时间区间、关键词等）按名字保存下来，下次一键套用。预设按 admin_user 隔离（本人只见
 * 本人的），并在保存时按白名单清洗，杜绝把任意 / 分页 / 恶意键写进预设。
 */
class AdminAuditPresetService
{
    /**
     * 允许存入预设的审计筛选字段（page/per_page 是分页，不入预设）。
     */
    public const ALLOWED_FILTER_KEYS = [
        'admin_user_id',
        'module',
        'action',
        'result',
        'keyword',
        'status_code',
        'from',
        'to',
    ];

    /**
     * 本人预设列表（时间倒序，不跨账号）。
     *
     * 作用：返回当前管理员保存的全部审计筛选预设，序列化成前端易用的数组。
     *
     * @param  AdminUser  $admin  当前管理员，用于按 owner 过滤。
     * @return array 预设数组，每项含 id / name / filters / created_at。
     */
    public function list(AdminUser $admin): array
    {
        return AdminAuditPreset::query()
            // 作用域强制在 owner，杜绝跨账号看到别人的预设。
            ->where('admin_user_id', $admin->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AdminAuditPreset $preset): array => [
                'id' => $preset->id,
                'name' => $preset->name,
                // filters 列可能是 JSON/null，统一强转数组给前端。
                'filters' => (array) $preset->filters,
                'created_at' => optional($preset->created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 保存（同名覆盖）一条预设。filters 先按白名单 + 去空清洗，杜绝存任意键。
     *
     * 作用：以 (owner, name) 为唯一键 upsert 一条预设。
     *
     * 「为什么」用 updateOrCreate：同名视为「更新」而非新增，避免同一管理员堆积重名预设。
     *
     * @param  AdminUser  $admin  当前管理员（owner）。
     * @param  string  $name  预设名。
     * @param  array  $filters  原始筛选条件，落库前经 sanitize 白名单清洗。
     * @return AdminAuditPreset 新建或更新后的预设。
     */
    public function save(AdminUser $admin, string $name, array $filters): AdminAuditPreset
    {
        return AdminAuditPreset::query()->updateOrCreate(
            // 唯一键：本人 + 预设名 → 同名即覆盖。
            ['admin_user_id' => $admin->id, 'name' => $name],
            // filters 序列化前先按白名单去空清洗，只存合法字段。
            ['filters' => $this->sanitize($filters)],
        );
    }

    /**
     * 删除本人一条预设（作用域在 WHERE，非 owner id 静默 no-op）。
     *
     * 作用：删除当前管理员名下指定 id 的预设。
     *
     * 「为什么」把 owner 条件写进 WHERE 而非先查后判：删别人的 id 会因作用域不命中而静默
     * no-op，天然防越权删除。
     *
     * @param  AdminUser  $admin  当前管理员（owner）。
     * @param  int  $presetId  待删除预设主键。
     * @return bool 实际删除行数 > 0 时返回 true。
     */
    public function delete(AdminUser $admin, int $presetId): bool
    {
        return AdminAuditPreset::query()
            // owner + 主键双重约束：非本人 id 命中 0 行，等于安全 no-op。
            ->where('admin_user_id', $admin->id)
            ->whereKey($presetId)
            ->delete() > 0;
    }

    /**
     * 作用：按 ALLOWED_FILTER_KEYS 白名单挑选并去空，产出可安全序列化入库的 filters。
     *
     * 「为什么」以白名单为迭代主体（而非遍历入参）：只有名单内的键才可能进入结果，
     * 任何额外 / 分页 / 恶意键都被天然丢弃；再剔除 null/''/[] 空值避免存无意义条件。
     *
     * @param  array  $filters  原始（可能含任意键）的筛选条件。
     * @return array 仅含白名单且非空的干净 filters。
     */
    private function sanitize(array $filters): array
    {
        $clean = [];

        // 以白名单为主循环：名单外的键根本没机会进入 $clean。
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            $value = $filters[$key] ?? null;

            // 空值（null/空串/空数组）不入预设，避免存无意义筛选条件。
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
