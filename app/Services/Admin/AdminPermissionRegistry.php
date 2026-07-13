<?php

namespace App\Services\Admin;

use App\Models\AdminPermission;
use App\Models\AdminRole;

class AdminPermissionRegistry
{
    public const PERMISSIONS = [
        'ops.dashboard.view' => ['name' => '运维总览查看', 'group' => 'ops', 'description' => '查看 Ops Center 总览'],
        'ops.logs.view' => ['name' => '日志中心查看', 'group' => 'ops', 'description' => '查看 Laravel、Octane、Redis 与系统日志'],
        'ops.alerts.view' => ['name' => '告警中心查看', 'group' => 'ops', 'description' => '查看告警列表与摘要'],
        'ops.alerts.manage' => ['name' => '告警处理', 'group' => 'ops', 'description' => '确认、恢复、测试通知和手动评估告警'],
        'ops.docker.view' => ['name' => 'Docker 查看', 'group' => 'ops', 'description' => '查看 Docker 容器、资源和日志'],
        'ops.docker.control' => ['name' => 'Docker 控制', 'group' => 'ops', 'description' => '启动、停止、重启 Docker 容器'],
        'ops.supervisor.view' => ['name' => 'Supervisor 查看', 'group' => 'ops', 'description' => '查看 Supervisor 进程和日志'],
        'ops.supervisor.control' => ['name' => 'Supervisor 控制', 'group' => 'ops', 'description' => '启动、停止、重启 Supervisor 进程'],
        'ops.system.view' => ['name' => '系统资源查看', 'group' => 'ops', 'description' => '查看 Redis、Queue、Disk、Network 等资源指标'],
        'admin.users.manage' => ['name' => '管理员管理', 'group' => 'admin', 'description' => '创建、编辑、禁用后台管理员和重置密码'],
        'admin.roles.manage' => ['name' => '角色权限管理', 'group' => 'admin', 'description' => '创建、编辑、禁用角色和分配权限'],
        'admin.audit.view' => ['name' => '审计日志查看', 'group' => 'admin', 'description' => '查看后台操作审计日志'],
    ];

    public static function slugs(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public function syncDefaults(): void
    {
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

        $this->syncRole('super_admin', '超级管理员', '拥有所有后台权限', self::slugs(), true);
        $this->syncRole('ops_admin', '运维管理员', '管理 Ops Center 日常运维功能', [
            'ops.dashboard.view',
            'ops.logs.view',
            'ops.alerts.view',
            'ops.alerts.manage',
            'ops.docker.view',
            'ops.docker.control',
            'ops.supervisor.view',
            'ops.supervisor.control',
            'ops.system.view',
        ], true);
        $this->syncRole('audit_viewer', '审计查看员', '查看 Ops 信息和审计日志', [
            'ops.dashboard.view',
            'ops.logs.view',
            'ops.alerts.view',
            'ops.docker.view',
            'ops.supervisor.view',
            'ops.system.view',
            'admin.audit.view',
        ], true);
    }

    private function syncRole(
        string $slug,
        string $name,
        string $description,
        array $permissionSlugs,
        bool $isSystem,
    ): void {
        $role = AdminRole::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'is_system' => $isSystem,
            ],
        );

        $permissionIds = AdminPermission::query()
            ->whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);
    }
}
