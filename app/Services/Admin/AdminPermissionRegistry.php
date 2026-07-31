<?php

namespace App\Services\Admin;

use App\Models\AdminPermission;
use App\Models\AdminRole;

/**
 * 后台管理 RBAC 权限注册表。
 *
 * 归属于 admin 安全子系统的「权限 / 角色（RBAC）」环节：以代码常量的形式声明
 * 系统内全部权限点及若干内置角色，并负责把它们幂等地同步到数据库。
 *
 * 设计要点：
 *  - PERMISSIONS 是权限的唯一事实来源（single source of truth），key 为权限 slug，
 *    value 描述其显示名、所属分组（ops / admin）与说明。
 *  - syncDefaults 通过 updateOrCreate 幂等落库，可反复执行（如部署/迁移时）而不产生重复。
 *  - 内置角色标记 is_system=true，代表由系统维护、不应被随意删除。
 */
class AdminPermissionRegistry
{
    // 全量权限点声明：slug => [显示名 name, 分组 group, 说明 description]
    // 分组约定：ops = Ops Center 运维相关，admin = 后台管理/安全相关
    public const PERMISSIONS = [
        'ops.dashboard.view' => ['name' => '运维总览查看', 'group' => 'ops', 'description' => '查看 Ops Center 总览'],
        'ops.release.view' => ['name' => '发布自检查看', 'group' => 'ops', 'description' => '查看并运行 Ops Center 发布自检'],
        'ops.inspections.view' => ['name' => '自动巡检查看', 'group' => 'ops', 'description' => '查看并运行 Ops Center 自动巡检'],
        'ops.logs.view' => ['name' => '日志中心查看', 'group' => 'ops', 'description' => '查看 Laravel、Octane、Redis 与系统日志'],
        'ops.alerts.view' => ['name' => '告警中心查看', 'group' => 'ops', 'description' => '查看告警列表与摘要'],
        'ops.alerts.manage' => ['name' => '告警处理', 'group' => 'ops', 'description' => '确认、恢复、测试通知和手动评估告警'],
        'ops.docker.view' => ['name' => 'Docker 查看', 'group' => 'ops', 'description' => '查看 Docker 容器、资源和日志'],
        'ops.docker.control' => ['name' => 'Docker 控制', 'group' => 'ops', 'description' => '启动、停止、重启 Docker 容器'],
        'ops.supervisor.view' => ['name' => 'Supervisor 查看', 'group' => 'ops', 'description' => '查看 Supervisor 进程和日志'],
        'ops.supervisor.control' => ['name' => 'Supervisor 控制', 'group' => 'ops', 'description' => '启动、停止、重启 Supervisor 进程'],
        'ops.system.view' => ['name' => '系统资源查看', 'group' => 'ops', 'description' => '查看 Redis、Queue、Disk、Network 等资源指标'],
        'ops.security.view' => ['name' => '安全总览查看', 'group' => 'ops', 'description' => '查看安全总览仪表盘（登录风控、失败登录、会话、2FA、IP 封禁、通道健康）'],
        'admin.users.manage' => ['name' => '管理员管理', 'group' => 'admin', 'description' => '创建、编辑、禁用后台管理员和重置密码'],
        'admin.roles.manage' => ['name' => '角色权限管理', 'group' => 'admin', 'description' => '创建、编辑、禁用角色和分配权限'],
        'admin.audit.view' => ['name' => '审计日志查看', 'group' => 'admin', 'description' => '查看后台操作审计日志'],
        'admin.security.manage' => ['name' => '安全准入管理', 'group' => 'admin', 'description' => '管理后台登录 IP 白/黑名单与准入策略'],
    ];

    /**
     * 作用：返回全部权限 slug 的平铺数组。
     *
     * @return array 所有权限的 slug 列表（即 PERMISSIONS 的键集合）
     *
     * 为什么单列此方法：super_admin 等「拥有全部权限」的角色以及各处校验
     * 都需要完整 slug 集合，集中在此避免各处硬编码。
     */
    public static function slugs(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * 作用：把权限点与内置角色幂等同步进数据库，是 RBAC 的初始化/自愈入口。
     *
     * @return void
     *
     * 为什么用 updateOrCreate：本方法可能在部署、迁移、seeding 时被反复调用；
     * 以 slug 为唯一键做 upsert 可保证多次执行结果一致、不产生重复权限或角色。
     */
    public function syncDefaults(): void
    {
        // 逐条 upsert 每个权限点：存在则更新名称/分组/说明，不存在则新建
        foreach (self::PERMISSIONS as $slug => $permission) {
            AdminPermission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                    'description' => $permission['description'],
                ],
            );
        }

        // 内置角色 1：超级管理员 —— 授予全部权限（self::slugs()），系统角色
        $this->syncRole('super_admin', '超级管理员', '拥有所有后台权限', self::slugs(), true);
        // 内置角色 2：运维管理员 —— 授予 ops.* 运维相关权限（含查看与控制）
        $this->syncRole('ops_admin', '运维管理员', '管理 Ops Center 日常运维功能', [
            'ops.dashboard.view',
            'ops.release.view',
            'ops.inspections.view',
            'ops.logs.view',
            'ops.alerts.view',
            'ops.alerts.manage',
            'ops.docker.view',
            'ops.docker.control',
            'ops.supervisor.view',
            'ops.supervisor.control',
            'ops.system.view',
            'ops.security.view',
        ], true);
        // 内置角色 3：审计查看员 —— 只读角色，仅授予各类 *.view 与审计查看权限，无任何控制权
        $this->syncRole('audit_viewer', '审计查看员', '查看 Ops 信息和审计日志', [
            'ops.dashboard.view',
            'ops.logs.view',
            'ops.alerts.view',
            'ops.docker.view',
            'ops.supervisor.view',
            'ops.system.view',
            'ops.security.view',
            'admin.audit.view',
        ], true);
    }

    /**
     * 作用：幂等地创建/更新一个角色，并把它与给定权限集重新绑定。
     *
     * @param  string  $slug  角色唯一标识（upsert 依据）
     * @param  string  $name  角色显示名
     * @param  string  $description  角色说明
     * @param  array  $permissionSlugs  该角色应拥有的权限 slug 列表
     * @param  bool  $isSystem  是否为系统内置角色（true 表示受保护、不应删除）
     * @return void
     *
     * 为什么用 sync 关联权限：sync 会以传入集合为准全量覆盖中间表，既能新增也能移除，
     * 保证角色的权限与代码声明严格一致，避免历史残留权限带来越权风险。
     */
    private function syncRole(
        string $slug,
        string $name,
        string $description,
        array $permissionSlugs,
        bool $isSystem,
    ): void {
        // 以 slug 为唯一键 upsert 角色本身
        $role = AdminRole::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'is_system' => $isSystem,
            ],
        );

        // 把权限 slug 解析为对应的权限主键 id 集合
        $permissionIds = AdminPermission::query()
            ->whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->all();

        // 全量同步角色-权限中间表：多则删、缺则补，最终与声明完全一致
        $role->permissions()->sync($permissionIds);
    }
}
